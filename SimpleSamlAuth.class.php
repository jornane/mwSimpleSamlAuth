<?php
/**
 * SimpleSamlAuth - LGPL 3.0 licensed
 * Copyright (C) 2014  Yørn de Jong
 *
 * SAML authentication class using SimpleSamlPhp.
 *
 * This class will log in users from SAML assertions received by SimpleSamlPhp.
 * It does so by settings hooks in MediaWiki which override the session handling system
 * and disable functionality that is redundant for federated logins.
 *
 * @file
 * @ingroup Extensions
 * @defgroup SimpleSamlAuth
 *
 * @license http://www.gnu.org/licenses/lgpl.html LGPL (GNU Lesser General Public License)
 * @copyright (C) 2014, Yørn de Jong
 * @author Yørn de Jong
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( "This is a MediaWiki extension, and must be run from within MediaWiki.\n" );
}

class SimpleSamlAuth {

	/** SAML Assertion Service */
	protected static $as;

	/** Whether $as is initialised */
	private static $initialised;

	/**
	 * Construct a new object and register it in $wgHooks.
	 * See README.md for possible values in $config.
	 *
	 * @param $config mixed[] Configuration settings for the SimpleSamlAuth extension.
	 *
	 * @return void
	 */
	private static function init() {
		if ( self::$initialised ) {
			return;
		}

		global $wgSamlSspRoot, $wgSamlAuthSource;

		// Load the simpleSamlPhp service
		require_once rtrim( $wgSamlSspRoot, DIRECTORY_SEPARATOR ) .
			DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . '_autoload.php';

		self::$as = new SimpleSAML_Auth_Simple( $wgSamlAuthSource );

		self::$initialised = true;
	}

	/**
	 * Bold hack to allow simpleSamlPhp to run with 'store.type' => 'phpsession'.
	 * This method must be called from LocalSettings.php after all variables have been set.
	 *
	 * All it does is initialise, and call ->isAuthenticated() on the SAML Assertion Service,
	 * thus claiming the PHP session before MediaWiki can.
	 *
	 * @return void
	 */
	public static function preload() {
		self::init();
		self::$as->isAuthenticated();
	}

	/**
	 * Disables preferences which are redundant while using an external authentication source.
	 * Password change is always disabled,
	 * e-mail settings are enabled/disabled based on the configuration.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 *
	 * @param $user User User whose preferences are being modified.
	 *                   ignored by this method because it checks the SAML assertion instead.
	 * @param &$preferences Preferences description array, to be fed to an HTMLForm object.
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookLimitPreferences( $user, &$preferences ) {
		self::init();
		global $wgSamlRequirement, $wgSamlRealnameAttr, $wgSamlMailAttr, $wgSamlConfirmMail;

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY || self::$as->isAuthenticated() ) {
			unset( $preferences['password'] );
			unset( $preferences['rememberpassword'] );
			if ( isset( $wgSamlRealnameAttr ) ) {
				unset( $preferences['realname'] );
			}
			if ( isset( $wgSamlMailAttr ) ) {
				unset( $preferences['emailaddress'] );
			}
		}

		return true;
	}
	/**
	 * Disables special pages which are redundant while using an external authentication source.
	 * Password change is always disabled,
	 * e-mail confirmation is disabled when autoconfirm is disabled.
	 *
	 * Note: When autoMailConfirm is true, but mailAttr is invalid,
	 * users will have no way to confirm their e-mail address.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/SpecialPage_initList
	 *
	 * @param $pages string[] List of special pages in MediaWiki
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookInitSpecialPages( &$pages ) {
		self::init();
		global $wgSamlRequirement, $wgSamlMailAttr, $wgSamlConfirmMail;

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY || self::$as->isAuthenticated() ) {
			unset( $pages['ChangePassword'] );
			unset( $pages['PasswordReset'] );
			if ( isset( $wgSamlMailAttr ) ) {
				unset( $pages['ConfirmEmail'] );
				unset( $pages['ChangeEmail'] );
			}
		}

		return true;
	}

	/**
	 * Hooked function, executed when the user visits the UserLogin page.
	 *
	 * If SimpleSamlAuth is configured not to allow local logons,
	 * a SAML assertion is required, which will most likely redirect the user.
	 * Otherwise, an error message is displayed explaining that the page is disabled.
	 *
	 * If SimpleSamlAuth is configured to allow local logons,
	 * an extra "field" is added to the logon form,
	 * which is a link/button which will redirect the user to SimpleSamlPhp to logon through SAML.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLoginForm
	 *
	 * @param $template UserloginTemplate
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookLoginForm( &$template ) {
		self::init();
		global $wgSamlRequirement;

		$url = self::$as->getLoginURL( Title::newMainPage()->getFullUrl() );

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY ) {
			self::$as->requireAuth( array(
				'ReturnTo' => Title::newMainPage()->getFullUrl()
			) );
			$err = wfMessage( 'simplesamlauth-pagedisabled' )->parse();
			return $err;
		}

		if ( !self::$as->isAuthenticated() ) {
			$template->set(
				'extrafields',
				'<a class="mw-ui-button mw-ui-constructive" href="'.htmlspecialchars( $url ).'">'.
				wfMessage( 'simplesamlauth-login' )->escaped().'</a>'
			);
		}

		return true;
	}

	/**
	 * Hooked function, executed when the user visits the UserLogout page.
	 * This hook will execute the SimpleSamlPhp Single Sign Out feature,
	 * so that the logout is propagated to the IdP.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLogout
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookLogout() {
		self::init();
		global $wgSamlPostLogoutRedirect;

		if ( self::$as->isAuthenticated() ) {
			if ( isset( $wgSamlPostLogoutRedirect ) ) {
				self::$as->logout( $wgSamlPostLogoutRedirect );
			} else {
				self::$as->logout();
			}
		}
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
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookLoadSession( $user, &$result ) {
		self::init();
		global $wgSamlRequirement, $wgSamlUsernameAttr;

		if ( $result ) {
			// Another hook already logged in
			if ( self::$as->isAuthenticated() ) {
				self::$as->logout();
			}
			return true;
		}

		if ( $wgSamlRequirement >= SAML_REQUIRED ) {
			self::$as->requireAuth();
		}

		self::loadUser( $user );

		/*
		 * ->isLoggedIn is a confusing name:
		 * it actually checks that user exists in DB
		 */
		if ( $user instanceof User && $user->isLoggedIn() ) {
			global $wgBlockDisablesLogin;
			if ( !$wgBlockDisablesLogin || !$user->isBlocked() ) {
				$attr = self::$as->getAttributes();
				if ( isset( $attr[$wgSamlUsernameAttr] )
					&& $attr[$wgSamlUsernameAttr] 
					&& strtolower( $user->getName()) ===
						strtolower( reset( $attr[$wgSamlUsernameAttr] ) )
				) {
					wfDebug( "User: logged in from SAML\n" );
					$result = true;
					return true;
				}
			}
		}
		if ( self::$as->isAuthenticated() ) {
			self::$as->logout();
		}
		return true;
	}

	/**
	 * Replace the MediaWiki login/logout links with direct links to SimpleSamlPhp.
	 * This takes away the need to set up a redirect on the special UserLogin and UserLogout pages,
	 * and as a side effect makes redirects after login/logout more predictable.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/PersonalUrls
	 *
	 * @param &$personal_urls array the array of URLs set up so far
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookPersonalUrls( array &$personal_urls ) {
		global $wgSamlRequirement, $wgSamlPostLogoutRedirect, $wgRequest;

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY || self::$as->isAuthenticated() ) {
			if ( isset( $personal_urls['logout'] ) ) {
				if ( isset( $wgSamlPostLogoutRedirect ) ) {
					$personal_urls['logout']['href'] =
						self::$as->getLogoutURL( $wgSamlPostLogoutRedirect );
				} elseif ( $wgSamlRequirement >= SAML_REQUIRED ) {
					$personal_urls['logout']['href'] =
						self::$as->getLogoutURL(
							self::$as->getLoginURL( Title::newMainPage()->getFullUrl() )
						);
				} else {
					$personal_urls['logout']['href'] =
						self::$as->getLogoutURL( $personal_urls['logout']['href'] );
				}
			}
			if ( !self::$as->isAuthenticated() ) {
				foreach( array( 'login', 'anonlogin' ) as $link ) {
					if ( isset( $personal_urls[$link] ) ) {
						if ( $returnTo = $wgRequest->getVal( 'returnto' ) ) {
							$url = Title::newFromText(
								$wgRequest->getVal( 'returnto' )
							)->getFullUrl();
							$personal_urls[$link]['href'] = self::$as->getLoginURL( $url );
						} else {
							$personal_urls[$link]['href'] = self::$as->getLoginURL();
						}
					}
				}
			}
		}
		return true;
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
	protected static function checkAttribute( $friendlyName, $attributeName, $attr ) {
		if ( isset( $attr[$attributeName] ) && $attr[$attributeName] ) {
			if ( count( $attr[$attributeName] ) != 1 ) {
				trigger_error(
					htmlspecialchars( $friendlyName ).
					' attribute "'.
					htmlspecialchars( $attributeName ).
					'" is multi-value, using only the first; '.
					htmlspecialchars( reset( $attr[$attributeName] ) )
					, E_USER_WARNING );
			}
		}
	}

	/**
	 * Return a user object that corresponds to the current SAML assertion.
	 * If no SAML assertion is set, the function returns null.
	 * If the user doesn't exist, and auto create has been turned on in the config,
	 * the user is created.
	 *
	 * If realnameAttr and/or mailAttr and/or groupMap are set in the config,
	 * these attributes are synchronised to the MediaWiki user.
	 * This also happens if the user already exists.
	 *
	 * @param $user MediaWiki user that must correspond to the SAML assertion
	 *
	 * @return void $user is modified on return
	 */
	protected static function loadUser( $user ) {
		if ( !self::$as->isAuthenticated() ) {
			return;
		}
		global $wgSamlUsernameAttr,
			$wgSamlCreateUser,
			$wgSamlRealnameAttr,
			$wgSamlMailAttr,
			$wgSamlConfirmMail;

		$attr = self::$as->getAttributes();

		if ( !isset( $attr[$wgSamlUsernameAttr] ) || !$attr[$wgSamlUsernameAttr] ) {
			wfDebug(
				'Username attribute "'.
				htmlspecialchars( $wgSamlUsernameAttr ).
				'" has no value; refusing login.'
			);
			return;
		}

		self::checkAttribute( 'Username', $wgSamlUsernameAttr, $attr );
		self::checkAttribute( 'Real name', $wgSamlRealnameAttr, $attr );
		self::checkAttribute( 'E-mail', $wgSamlMailAttr, $attr );

		/*
		 * The temp user is created because ->load() doesn't override
		 * the username, which can lead to incorrect capitalisation.
		 */
		$tempUser = User::newFromName( ucfirst( reset( $attr[$wgSamlUsernameAttr] ) ) );
		$tempUser->load();
		$id = $tempUser->getId();
		if ( !$id ) {
			if ( $wgSamlCreateUser ) {
				$tempUser->addToDatabase();
				$id = $tempUser->getId();
			} else {
				wfDebug( 'User '.htmlspecialchars( reset( $attr[$wgSamlUsernameAttr] ) ).
					' doesn\'t exist and "autoCreate" flag is false.'
				);
			}
		}
		if ( $id ) {
			$user->setId( $id );
			$user->loadFromId();
			$changed = false;
			if ( isset( $wgSamlRealnameAttr )
				&& isset( $attr[$wgSamlRealnameAttr] )
				&& $user->getRealName() !== reset( $attr[$wgSamlRealnameAttr] )
			) {
				$changed = true;
				$user->setRealName( reset( $attr[$wgSamlRealnameAttr] ) );
			}
			if ( isset( $wgSamlMailAttr )
				&& isset( $attr[$wgSamlMailAttr] )
				&& $user->getEmail() !== reset( $attr[$wgSamlMailAttr] )
			) {
				$changed = true;
				$user->setEmail( reset( $attr[$wgSamlMailAttr] ) );
				if ( isset( $wgSamlConfirmMail ) && $wgSamlConfirmMail ) {
					$user->ConfirmEmail();
				}
			}
			if ( $changed ) {
				$user->saveSettings();
			}
			self::setGroups( $user, $attr );
		}
	}

	/**
	 * Add groups based on the existence of attributes in the SAML assertion.
	 *
	 * @param $user User add MediaWiki permissions to this user from its SAML assertion
	 * @param $attr string[][] SAML attributes from assertion
	 *
	 * @return void $user is modified on return
	 */
	protected static function setGroups( $user, $attr ) {
		global $wgSamlGroupMap;
		
		foreach( $wgSamlGroupMap as $group => $rules ) {
			foreach( $rules as $attrName => $needles ) {
				if ( !isset( $attr[$attrName] ) ) {
					continue;
				}
				foreach( $needles as $needle ) {
					if ( in_array( $needle, $attr[$attrName] ) ) {
						$user->addGroup( $group );
					} else {
						$user->removeGroup( $group );
					}
				}
			}
		}
	}
}
