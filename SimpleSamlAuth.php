<?php
/**
 * Main file for the SimpleSamlAuth extension.
 *
 * @file
 * @ingroup Extensions
 * @defgroup SimpleSamlAuth
 *
 * @link https://www.mediawiki.org/wiki/Extension:SimpleSamlAuth Documentation
 * @link https://www.mediawiki.org/wiki/Extension_talk:SimpleSamlAuth Support
 * @link https://github.com/yorn/mwSimpleSamlAuth Source Code
 *
 * @license http://www.gnu.org/licenses/lgpl.html LGPL (GNU Lesser General Public License)
 * @copyright (C) 2014, Yørn de Jong
 * @author Yørn de Jong
 */

if (!defined('MEDIAWIKI')) {
	die("This is a MediaWiki extension, and must be run from within MediaWiki.\n");
}

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'SimpleSamlAuth',
	'version' => 'GIT-master',
	'author' => 'Yørn de Jong',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SimpleSamlAuth',
	'descriptionmsg' => 'simplesamlauth-desc'
);

$wgExtensionMessagesFiles['SimpleSamlAuth'] = __DIR__ . '/SimpleSamlAuth.i18n.php';

class SimpleSamlAuth {

	protected $authSource = 'default-sp';
	protected $usernameAttr = 'uid';
	protected $realnameAttr = 'cn';
	protected $mailAttr = 'mail';
	protected $autoCreate = false;
	protected $samlRequired = false;
	protected $samlOnly = false;
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
	 *
	 * @param $config mixed[] Configuration settings for the SimpleSamlAuth extension.
	 */
	public static function registerHook($config) {
		global $wgHooks;
		$auth = new SimpleSamlAuth($config);
		$wgHooks['UserLoadFromSession'][] =
			array($auth, 'hookLoadSession');
		$wgHooks['GetPreferences'][] =
			array($auth, 'hookLimitPreferences');
		$wgHooks['SpecialPage_initList'][] =
			array($auth, 'hookInitSpecialPages');
		$wgHooks['UserLoginForm'][] =
			array($auth, 'hookLoginForm');
	}

	/**
	 * Construct a new object and register it in $wgHooks.
	 * See README.md for possible values in $config.
	 *
	 * @param $config mixed[] Configuration settings for the SimpleSamlAuth extension.
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
			$this->sspRoot = rtrim(__DIR__, DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. 'simplesamlphp'
				. DIRECTORY_SEPARATOR
				;
		}
		if (array_key_exists('autoCreate', $config)) {
			$this->autoCreate = $config['autoCreate'];
		} elseif (array_key_exists('autocreate', $config)) {
			$this->autoCreate = $config['autocreate']; // Legacy
			trigger_error(
				'SimpleSamlAuth config flag "autocreate" should be "autoCreate"',
				E_USER_NOTICE
			);
		}
		if (array_key_exists('samlRequired', $config)) {
			$this->samlRequired = $config['samlRequired'];
		} elseif (array_key_exists('readHook', $config)) {
			$this->samlRequired = $config['readHook']; // Legacy
			trigger_error(
				'SimpleSamlAuth config flag "readHook" should be "samlRequired"',
				E_USER_NOTICE
			);
		}
		if ($this->samlRequired || array_key_exists('samlOnly', $config)) {
			$this->samlOnly = $this->samlRequired || $config['samlOnly'];
		}
		if (array_key_exists('autoMailConfirm', $config)) {
			$this->autoMailConfirm = $config['autoMailConfirm'];
		}
		if (array_key_exists('postLogoutRedirect', $config)) {
			$this->postLogoutRedirect = $config['postLogoutRedirect'];
		}

		// Load the simpleSamlPhp service
		require_once $this->sspRoot . 'lib' . DIRECTORY_SEPARATOR . '_autoload.php';

		$this->as = new SimpleSAML_Auth_Simple($this->authSource);
	}

	/**
	 * Disables preferences which are redundant while using an external authentication source.
	 * Password change is always disabled, e-mail settings are enabled/disabled based on the configuration.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 *
	 * @param $user User User whose preferences are being modified.
	 *                   ignored by this method because it checks the SAML assertion instead.
	 * @param &$preferences Preferences description array, to be fed to an HTMLForm object.
	 *
	 * @return boolean|string TRUE on success, FALSE on silent error, string on verbose error 
	 */
	function hookLimitPreferences($user, &$preferences) {
		if ($this->as->isAuthenticated()) {
			unset($preferences['password']);
			unset($preferences['rememberpassword']);
			if ($this->autoMailConfirm) {
				unset($preferences['emailaddress']);
			}
			return true;
		}
		return false;
	}
	/**
	 * Disables special pages which are redundant while using an external authentication source.
	 * Password change is always disabled, e-mail confirmation is disabled when autoconfirm is disabled.
	 *
	 * Note: When autoMailConfirm is true, but mailAttr is invalid,
	 * users will have no way to confirm their e-mail address.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/SpecialPage_initList
	 *
	 * @param $pages string[] List of special pages in MediaWiki
	 *
	 * @return boolean|string TRUE on success, FALSE on silent error, string on verbose error 
	 */
	public function hookInitSpecialPages(&$pages) {
		if ($this->samlOnly || $this->as->isAuthenticated()) {
			unset($pages['ChangePassword']);
			unset($pages['PasswordReset']);
			if ($this->autoMailConfirm) {
				unset($pages['ConfirmEmail']);
			}
			return true;
		}
		return false;
	}

	/**
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLoginForm
	 *
	 * @param $template UserloginTemplate
	 *
	 * @return boolean|string TRUE on success, FALSE on silent error, string on verbose error 
	 */
	function hookLoginForm(&$template) {
		$template->set(
			'extrafields',
			'<a class="mw-ui-button mw-ui-constructive" href="'.htmlspecialchars($this->as->getLoginURL($this->getReturnUrl())).'">'.
			wfMessage('simplesamlauth-login')->escaped().'</a>'
		);
		return true;
	}

	/**
	 * Hooked function, if a SAML assertion exist,
	 * log in the corresponding MediaWiki user or logout from SAML.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLoadFromSession
	 *
	 * @param $user User MediaWiki User object
	 * @param $result boolean a user is logged in
	 *
	 * @return boolean|string TRUE on success, FALSE on silent error, string on verbose error 
	 */
	public function hookLoadSession($user, &$result) {
		if ($this->samlRequired) {
			$this->as->requireAuth(array('returnTo' => $this->getReturnUrl()));
		}
		if (isset($_REQUEST['title'])) {
			global $wgLanguageCode;
			$lg = Language::factory($wgLanguageCode);

			if ($this->as->isAuthenticated()) {
				if ($_REQUEST['title'] === $lg->specialPage('Userlogout')) {
					if (isset($this->postLogoutRedirect)) {
						$this->as->logout($this->postLogoutRedirect);
					} else {
						$this->as->logout(Title::newMainPage()->getFullUrl());
					}
				}
			}
			if ($this->samlOnly && $_REQUEST['title'] === $lg->specialPage('Userlogin')) {
				$this->redirect();
				return null;
			}
		}

		$this->loadUser($user);

		/*
		 * ->isLoggedIn is a confusing name:
		 * it actually checks that user exists in DB
		 */
		if ($user instanceof User && $user->isLoggedIn()) {
			global $wgBlockDisablesLogin;
			if (!$wgBlockDisablesLogin || !$user->isBlocked()) {
				$attr = $this->as->getAttributes();
				if (isset($attr[$this->usernameAttr]) && $attr[$this->usernameAttr] && strtolower($user->getName()) === strtolower(reset($attr[$this->usernameAttr]))) {
					wfDebug("User: logged in from SAML\n");
					$result = true;
					return true;
				}
			}
		}
		if ($this->as->isAuthenticated()) {
			$this->as->logout();
		}
		return null;
	}

	/**
	 * Redirect to the page the user was visiting,
	 * or to the main page if no page could be determined.
	 *
	 * This function is used to block access to the UserLogin page,
	 * which users may visit due to cache.
	 *
	 * @return void function should not return
	 */
	protected function redirect() {
		$this->as->requireAuth(array('returnTo' => $this->getReturnUrl()));
		global $wgOut;
		$wgOut->redirect($this->getReturnUrl());
	}

	/**
	 * Trigger an error if an attribute contains more than one value.
	 * This function should only be executed on attributes that are used for
	 * username, real name and e-mail.
	 *
	 * @param $friendlyName string human-readable name of the attribute
	 * @param $attributeName string name of the attribute in the SAML assertion
	 * @param $attr string[][] SAML attributes from assertion
	 *
	 * @return void errors may be triggered on return
	 */
	protected static function checkAttribute($friendlyName, $attributeName, $attr) {
		if (isset($attr[$attributeName]) && $attr[$attributeName]) {
			if (count($attr[$attributeName]) != 1) {
				trigger_error(
					htmlspecialchars($friendlyName).
					' attribute "'.
					htmlspecialchars($attributeName).
					'" is multi-value, using only the first; '.
					htmlspecialchars(reset($attr[$attributeName]))
					, E_USER_WARNING);
			}
		}
	}

	/**
	 * Return a user object that corresponds to the current SAML assertion.
	 * If no SAML assertion is set, the function returns NULL.
	 * If the user doesn't exist, and auto create has been turned on in the config,
	 * the user is created.
	 *
	 * If realnameAttr and/or mailAttr and/or groupMap are set in the config,
	 * these attributes are synchronised to the Mediawiki user.
	 * This also happens if the user already exists.
	 *
	 * @param $user MediaWiki user that must correspond to the SAML assertion
	 *
	 * @return void $user is modified on return
	 */
	protected function loadUser($user) {
		if (!$this->as->isAuthenticated()) {
			return;
		}
		$attr = $this->as->getAttributes();

		if (!isset($attr[$this->usernameAttr]) || !$attr[$this->usernameAttr]) {
			wfDebug(
				'Username attribute "'.
				htmlspecialchars($this->usernameAttr).
				'" has no value; refusing login'
			);
			return;
		}

		$this->checkAttribute('Username', $this->usernameAttr, $attr);
		$this->checkAttribute('Real name', $this->realnameAttr, $attr);
		$this->checkAttribute('E-mail', $this->mailAttr, $attr);

		/*
		 * The temp user is created because ->load() doesn't override
		 * the username, which can lead to incorrect capitalisation.
		 */
		$tempUser = User::newFromName(ucfirst(reset($attr[$this->usernameAttr])));
		$tempUser->load();
		$user->setRealName(reset($attr[$this->realnameAttr]));
		$user->setEmail(reset($attr[$this->mailAttr]));
		$this->setGroups($user, $attr);
		$id = $tempUser->getId();
		if ($id) {
			$user->setId($id);
			$user->loadFromId();
		} else {
			if ($this->autoCreate) {
				$user->setName(reset($attr[$this->usernameAttr]));
				$user->addToDatabase();
			} else {
				wfDebug('User '.htmlspecialchars(reset($attr[$this->usernameAttr])).
					' doesn\'t exist and "autoCreate" flag is FALSE.'
				);
			}
		}
	}

	/**
	 * Create URL where the user should be directed after login
	 *
	 * @return string url
	 */
	protected static function getReturnUrl() {
		global $wgRequest;
		$returnto = $wgRequest->getVal('returnto');
		if ($returnto) {
			$target = Title::newFromText($returnto);
		}
		if (!$target || $target->getNamespace() == NS_SPECIAL) {
			$target = Title::newMainPage();
		}
		return $target->getFullUrl();
	}

	/**
	 * Add groups based on the existence of attributes in the SAML assertion.
	 *
	 * @param $user User add MediaWiki permissions to this user from its SAML assertion
	 * @param $attr string[][] SAML attributes from assertion
	 *
	 * @return void $user is modified on return
	 */
	public function setGroups($user, $attr) {
		foreach($this->groupMap as $group => $rules) {
			foreach($rules as $attrName => $needles) {
				if (!isset($attr['attrName'])) {
					continue;
				}
				foreach($needles as $needle) {
					if (in_array($needle, $attr[$attrName])) {
						$user->addGroup($group);
					} else {
						$user->removeGroup($group);
					}
				}
			}
		}
	}
}
