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
	 * Disables preferences which are redundant while using an external authentication source.
	 * Password change and e-mail settings are always disabled,
	 * Real name is only disabled if it is obtained from SAML.
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
		global $wgSamlRequirement, $wgSamlRealnameAttr;

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY || self::$as->isAuthenticated() ) {
			unset( $preferences['password'] );
			unset( $preferences['rememberpassword'] );
			if ( isset( $wgSamlRealnameAttr ) ) {
				unset( $preferences['realname'] );
			}
			unset( $preferences['emailaddress'] );
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
		global $wgSamlRequirement;

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY || self::$as->isAuthenticated() ) {
			unset( $pages['ChangePassword'] );
			unset( $pages['PasswordReset'] );
			unset( $pages['ConfirmEmail'] );
			unset( $pages['ChangeEmail'] );
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
				'ReturnTo' => Title::newMainPage()->getFullUrl(),
				'KeepPost' => FALSE,
			) );
			$err = wfMessage( 'simplesamlauth-pagedisabled' )->parse();
			return $err;
		}

		if ( !self::$as->isAuthenticated() ) {
			$template->set(
				'extrafields',
				'<a class="mw-ui-button mw-ui-constructive" href="'
				. htmlentities( $url )
				. '">'
				. wfMessage( 'simplesamlauth-login' )->escaped()
				. '</a>'
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
		global $wgSamlRequirement, $wgSamlUsernameAttr, $wgBlockDisablesLogin;

		if ( $result ) {
			// Another hook already logged in
			if ( self::$as->isAuthenticated() ) {
					wfDebug( "Both SAML and local user logged in; logging out SAML.\n" );
				self::$as->logout();
			}
			return true;
		}

		if ( $wgSamlRequirement >= SAML_REQUIRED ) {
			self::$as->requireAuth();
		}

		try {
			self::loadUser( $user );
		} catch (Exception $e) {
			return $e->getMessage();
		}

		/*
		 * ->isLoggedIn is a confusing name:
		 * it actually checks that user exists in DB
		 */
		if ( $user instanceof User && $user->isLoggedIn() ) {
			if ( !$wgBlockDisablesLogin || !$user->isBlocked() ) {
				$attr = self::$as->getAttributes();
				if ( isset( $attr[$wgSamlUsernameAttr] )
					&& $attr[$wgSamlUsernameAttr] 
					&& strtolower( $user->getName()) ===
						strtolower( reset( $attr[$wgSamlUsernameAttr] ) )
				) {
					// Ensure we have a PHP session in place.
					// This is required for compatibility with User::matchEditToken(string)
					wfSetupSession();
					$result = true;
					return true;
				} else {
					return 'Refusing login because MediaWiki username "'
						. htmlentities($user->getName())
						. '" does not match SAML username "'
						. htmlentities( reset( $attr[$wgSamlUsernameAttr] ) )
						. "\"\n"
					);
				}
			} else {
				return 'Refusing login due to user "'.htmlentities($user->getName())."\" being blocked.\n";
			}
		}
		if ( self::$as->isAuthenticated() ) {
			wfDebug( 'Unable to login despite a valid SSP session. '
				. "Logging out from SSP in case this is a transient error.\n"
				);
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
	 * @param Title $title the Title object of the current article
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookPersonalUrls( array &$personal_urls, Title $title ) {
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
						} elseif ( $title->isSpecial( 'Userlogout' ) ) {
							$personal_urls[$link]['href'] = self::$as->getLoginURL(
								Title::newMainPage()->getFullUrl()
							);
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
	 * Use this to do something completely different, after the basic globals have been set up, but before ordinary actions take place.
	 *
	 * Takes control of the session before a stray SubmitAction calls wfSetupSession() for us.
	 * This is a bug in MediaWiki which has not been fixed yet.
	 * 
	 * @link https://bugzilla.wikimedia.org/show_bug.cgi?id=65493
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/MediaWikiPerformAction
	 *
	 * @param object $output $wgOut
	 * @param object $article $wgArticle
	 * @param object $title $wgTitle
	 * @param object $user $wgUser
	 * @param object $request $wgRequest
	 * @param object $wiki MediaWiki object, added in 1.13
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error 
	 */
	public static function hookMediaWikiPerformAction( $output, $article, $title, $user, $request, $wiki ) {
		if (strtolower($request->getText('action')) == 'submit') {
			if ($_SERVER['REQUEST_METHOD'] == 'POST') {
				$user->load();
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
	 * @param $required boolean Whether the attribute is required;
	 * 	function will return false if it is not available
	 *
	 * @return boolean whether login can continue
	 */
	protected static function checkAttribute( $friendlyName, $attributeName, $attr, $required ) {
		if ( $required && ( !isset( $attr[$attributeName] ) || !$attr[$attributeName] ) ) {
			throw new Exception(
				htmlspecialchars( $friendlyName ).
				' SAML attribute "'.
				htmlspecialchars( $attributeName ).
				'" not configured; refusing login.'
			);
		}
		if ( isset( $attr[$attributeName] ) && $attr[$attributeName] ) {
			if ( count( $attr[$attributeName] ) != 1 ) {
				throw new Exception(
					htmlspecialchars( $friendlyName ).
					' SAML attribute "'.
					htmlspecialchars( $attributeName ).
					'" is multi-value, using only the first value; '.
					htmlspecialchars( reset( $attr[$attributeName] ) )
				);
			}
		}
		return true;
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
			$wgSamlMailAttr;

		$attr = self::$as->getAttributes();

		if ( !self::checkAttribute( 'Username', $wgSamlUsernameAttr, $attr, true )
			|| !self::checkAttribute( 'Real name', $wgSamlRealnameAttr, $attr, false )
			|| !self::checkAttribute( 'E-mail', $wgSamlMailAttr, $attr, true )
		) {
			return;
		}

		$username = ucfirst( reset( $attr[$wgSamlUsernameAttr] ) );

		if ( !User::isUsableName( $username ) ) {
			throw new Exception( 'Username "'
				. htmlentities($username)
				. "\" is not a valid MediaWiki username.\n"
			);
		}

		/*
		 * The temp user is created because ->load() doesn't override
		 * the username, which can lead to incorrect capitalisation.
		 */
		$tempUser = User::newFromName( $username );
		$tempUser->load();
		$id = $tempUser->getId();
		if ( !$id ) {
			if ( $wgSamlCreateUser ) {
				$tempUser->addToDatabase();
				$id = $tempUser->getId();
			} else {
				throw new Exception( 'User "'
					. htmlentities( reset( $attr[$wgSamlUsernameAttr] ) )
					. "\" does not exist and \"\$wgSamlCreateUser\" flag is false.\n"
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
			if ( $user->getEmail() !== reset( $attr[$wgSamlMailAttr] ) ) {
				$changed = true;
				$user->setEmail( reset( $attr[$wgSamlMailAttr] ) );
				$user->ConfirmEmail();
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
