<?php
/**
 * SimpleSamlAuth.php
 * Written by Yørn de Jong
 * @license: LGPL (GNU Lesser General Public License) http://www.gnu.org/licenses/lgpl.html
 *
 * @author Yørn de Jong
 *
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}


class SimpleSamlAuth {

	protected $authSource = 'default-sp';
	protected $usernameAttr = 'uid';
	protected $realnameAttr = 'cn';
	protected $mailAttr = 'mail';
	protected $autocreate = false;
	protected $readHook = false;
	protected $autoMailConfirm = false;
	protected $sspRoot;
	protected $postLogoutRedirect;
	protected $groupMap = array (
			'sysop' => array (
				'groups' => array('admin'),
			),
			'bureaucrat' => array (
				'groups' => array('admin'),
			),
		);

	/* SAML Assertion Service */
	protected $as;

	/**
	 * Convenience function to make the config file prettier.
	 */
	public static function registerHook($config) {
		new SimpleSamlAuth($config);
	}

	/**
	 * Construct a new object and register it in $wgHooks.
	 * See README.md for possible values in $config.
	 */
	public function __construct($config) {
		if (array_key_exists('authSource', $config)) {
			$this->authSource = $config['authSource'];
		}
		if (array_key_exists('usernameAttr', $config)) {
			$this->usernameAttr = $config['usernameAttr'];
		}
		if (array_key_exists('realnameAttr', $config)) {
			$this->realnameAttr = $config['realnameAttr'];
		}
		if (array_key_exists('mailAttr', $config)) {
			$this->mailAttr = $config['mailAttr'];
		}
		if (array_key_exists('groupMap', $config)) {
			$this->groupMap = $config['groupMap'];
		}
		if (array_key_exists('sspRoot', $config)) {
			$this->sspRoot = rtrim($config['sspRoot'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		}
		else
		{
			$this->sspRoot = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. 'simplesamlphp'
				. DIRECTORY_SEPARATOR
				;
		}
		if (array_key_exists('autocreate', $config)) {
			$this->autocreate = $config['autocreate'];
		}
		if (array_key_exists('readHook', $config)) {
			$this->readHook = $config['readHook'];
		}
		if (array_key_exists('autoMailConfirm', $config)) {
			$this->autoMailConfirm = $config['autoMailConfirm'];
		}
		if (array_key_exists('postLogoutRedirect', $config)) {
			$this->postLogoutRedirect = $config['postLogoutRedirect'];
		}

		require_once $this->sspRoot . 'lib' . DIRECTORY_SEPARATOR . '_autoload.php';

		$this->as = new SimpleSAML_Auth_Simple($this->authSource);

		// Triggers handling of SAML assertion before Mediawiki framework throws it out.
		$this->as->isAuthenticated();

		global $wgHooks;
		$wgHooks['UserLoadFromSession'][] = array($this, 'login');
		if ($this->readHook) {
			$wgHooks['TitleReadWhitelist'][] = array($this, 'onTitleReadWhitelist');
		}
	}

	/**
	 * Hooked function, will require a SAML assertion if one doesn't already exist.
	 * Used to skip the "Login required" screen and continue to the login page rightaway.
	 */
	public function onTitleReadWhitelist($title, $user, &$whitelisted) {
		if (!$this->readHook)
			return false;
		if (!$this->as->isAuthenticated())
			$this->as->requireAuth();
		return false;
	}

	/**
	 * Hooked function, will log the user in if a SAML assertion exists,
	 * and will require an assertion if the Userlogin page is opened.
	 */
	public function login($user, &$result) {
		global $wgLanguageCode;

		if (isset($_REQUEST['title'])) {
			$lg = Language::factory($wgLanguageCode);
			if ($user->isLoggedIn() && !$this->as->isAuthenticated() || $_REQUEST['title'] == $lg->specialPage('Userlogout')) {
				$this->logout($user);
			}
			else if ($this->as->isAuthenticated() && !$user->isLoggedIn() || $_REQUEST['title'] == $lg->specialPage('Userlogin')) {
				$this->sync();
			}
		}

		return true;
	}

	/**
	 * Require a SAML assertion and log the corresponding user in.
	 * If the user doesn't exist, and autocreate has been turned on in the config,
	 * the user is created.
	 *
	 * If realnameAttr and/or mailAttr and/or groupMap are set in the config,
	 * these attributes are synchronised to the Mediawiki user.
	 * This also happens if the user already exists.
	 */
	protected function sync() {
		{
			global $wgRequest, $wgOut;
			$this->as->requireAuth();

			$attr = $this->as->getAttributes();

			if (!array_key_exists($this->usernameAttr, $attr) || count($attr[$this->usernameAttr]) != 1)
				return false; // No username attribute in SAML assertion, bailing.

			$u = User::newFromName($attr[$this->usernameAttr][0]);

			if (array_key_exists($this->realnameAttr, $attr) && count($attr[$this->realnameAttr]) == 1) {
				$u->setRealName($attr[$this->realnameAttr][0]);
			}
			if (array_key_exists($this->mailAttr, $attr) && count($attr[$this->mailAttr]) == 1) {
				$u->setEmail($attr[$this->mailAttr][0]);
				if ($this->autoMailConfirm && !$u->isEmailConfirmed()) {
					$u->confirmEmail();
				}
			}
			$this->setGroups($u, $attr);

			if ($u->getID() == 0) {
				if ($this->autocreate) {
					$u->setPassword(uniqid()); // do something random
					$u->addToDatabase();
				}
				else
				{
					return true;
				}
			}

			$u->setCookies();
			$u->saveSettings();

			// Redirect if a returnto parameter exists
			$returnto = $wgRequest->getVal("returnto");
			if ($returnto) {
				$target = Title::newFromText($returnto);
				if ($target) {
					// Make sure we don't try to redirect to logout !
					if ($target->getNamespace() == NS_SPECIAL)
						$url = Title::newMainPage()->getFullUrl();
					else
						$url = $target->getFullUrl();

					$wgOut->redirect($url."?action=purge"); //action=purge is used to purge the cache
				}
			}
		}
	}

	/**
	 * End the current Mediawiki and send a logout signal to the SAML IdP.
	 */
	protected function logout($user) {
		$user->logout();
		if (isset($this->postLogoutRedirect)) {
			$this->as->logout($this->postLogoutRedirect);
		} else {
			$this->as->logout(Title::newMainPage()->getFullUrl());
		}
	}

	/**
	 * Add groups based on the existence of attributes in the SAML assertion.
	 */
	public function setGroups($u, $attr) {
		foreach($this->groupMap as $group => $rules) {
			foreach($rules as $attrName => $needles) {
				if (!array_key_exists($attrName, $attr))
					 continue;
				foreach($needles as $needle) {
					if (in_array($needle, $attr[$attrName])) {
						$u->addGroup($group);
					}
					else
					{
						$u->removeGroup($group);
					}
				}
			}
		}
	}
}
