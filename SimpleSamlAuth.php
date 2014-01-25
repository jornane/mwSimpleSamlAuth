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
	
	/* Cached value of $as->isAuthenticated() */
	private $authenticated = false;

	/**
	 * Convenience function to make the config file prettier.
	 */
	public static function registerHook($config) {
		global $wgHooks;
		$auth = new SimpleSamlAuth($config);
		$wgHooks['UserLoadFromSession'][] =
			array($auth, 'login');
		$wgHooks['GetPreferences'][] =
			array($auth, 'limitPreferences');
		$wgHooks['SpecialPage_initList'][] =
			array($auth, 'limitSpecialPages');
		$wgHooks['TitleReadWhitelist'][] =
			array($auth, 'onTitleReadWhitelist');
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

		// Load the simpleSamlPhp framework
		require_once $this->sspRoot . 'lib' . DIRECTORY_SEPARATOR . '_autoload.php';

		$this->as = new SimpleSAML_Auth_Simple($this->authSource);

		/*
		 * Triggers handling of SAML assertion before the Mediawiki framework redirects us.
		 * Calling this method now will allow us to call $this->as->getAttributes() later.
		 */
		$this->authenticated = $this->as->isAuthenticated();
	}

	/**
	 * Disables preferences which are redundant while using an external authentication source.
	 * Password change is always disabled, e-mail settings are enabled/disabled based on the configuration.
	 */
	function limitPreferences($user, &$preferences) {
		unset($preferences['password']);
		unset($preferences['rememberpassword']);
		if ($this->autoMailConfirm) {
			unset($preferences['emailaddress']);
		}
		return true;
	}
	/**
	 * Disables special pages which are redundant while using an external authentication source.
	 * Password change is always disabled, e-mail confirmation is disabled when autoconfirm is disabled.
	 *
	 * Note: When autoMailConfirm is true, but mailAttr is invalid,
	 * users will have no way to confirm their e-mail address.
	 */
	public function limitSpecialPages(&$pages) {
		unset($pages['ChangePassword']);
		unset($pages['PasswordReset']);
		if ($this->autoMailConfirm) {
			unset($pages['ConfirmEmail']);
		}
		return true;
	}

	/**
	 * Hooked function, will require a SAML assertion if one doesn't already exist.
	 * Used to skip the "Login required" screen and continue to the login page rightaway.
	 */
	public function onTitleReadWhitelist($title, $user, &$whitelisted) {
		if (!$this->readHook)
			return false;
		if (!$this->authenticated)
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

			$logoutClicked = $_REQUEST['title'] == $lg->specialPage('Userlogout');

			$loginClicked = !$logoutClicked && $_REQUEST['title'] == $lg->specialPage('Userlogin');

			/**
			 * There is a valid Mediawiki login,
			 * but there is no SAML assertion.
			 * @todo check if SAML assertion matches Mediawiki user.
			 */
			$invalidLogin = $user->isLoggedIn() && !$this->authenticated;

			/**
			 * There is a valid SAML assertion,
			 * but Mediawiki is not logged in.
			 */
			$unsyncedLogin = $this->authenticated && !$user->isLoggedIn();

			if ($invalidLogin || $logoutClicked) {
				$this->logout($user);
			}
			else if ($unsyncedLogin || $loginClicked) {
				$syncSuccess = $this->sync();
			}
			if ($unsyncedLogin && $loginClicked && !$syncSuccess) {
				/**
				 * Syncing failed, meaning that there is a SAML assertion but Mediawiki login failed.
				 * This can happen if the Mediawiki user does not exist, or the username field doesn't exist.
				 * The situation is resolved by removing the SAML assertion, which is done by logging out SAML.
				 */
				$this->as->logout();
			}
		}

		return true;
	}

	/**
	 * Require a SAML assertion and log the corresponding user in.
	 * If the user doesn't exist, and autocreate has been turned on in the config,
	 * the user is created.
	 *
	 * Because this function requires a SAML assertion, it may redirect the user to the IdP and exit.
	 *
	 * If realnameAttr and/or mailAttr and/or groupMap are set in the config,
	 * these attributes are synchronised to the Mediawiki user.
	 * This also happens if the user already exists.
	 *
	 * @return whether a Mediawiki logon was performed
	 */
	protected function sync() {
		$this->as->requireAuth();

		$attr = $this->as->getAttributes();

		if (!array_key_exists($this->usernameAttr, $attr) || count($attr[$this->usernameAttr]) != 1) {
			// No username attribute in SAML assertion, bailing.
			return false;
		}

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
				$u->addToDatabase();
			}
			else
			{
				// User doesn't exist and autocration is off
				return false;
			}
		}

		$u->setCookies();
		$u->saveSettings();

		$this->redirect();
		return true;
	}
	
	/**
	 * Redirect back to the requested page after logging in.
	 * If the requested page was a special page, redirect to the main page.
	 */
	protected function redirect() {
		global $wgRequest, $wgOut;
		$returnto = $wgRequest->getVal('returnto');
		if ($returnto) {
			$target = Title::newFromText($returnto);
			if ($target) {
				// Make sure we don't try to redirect to logout !
				if ($target->getNamespace() == NS_SPECIAL) {
					$url = Title::newMainPage()->getFullUrl();
				} else {
					$url = $target->getFullUrl();
				}
				$wgOut->redirect($url.'?action=purge'); //action=purge is used to purge the cache
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
				if (!array_key_exists($attrName, $attr)) {
					continue;
				}
				foreach($needles as $needle) {
					if (in_array($needle, $attr[$attrName])) {
						$u->addGroup($group);
					} else {
						$u->removeGroup($group);
					}
				}
			}
		}
	}
}
