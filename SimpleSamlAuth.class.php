<?php
/**
 * SimpleSamlAuth - LGPL 3.0 licensed
 * Copyright (C) 2015  Jørn Åne
 *
 * SAML authentication class using SimpleSAMLphp.
 *
 * This class will log in users from SAML assertions received by SimpleSAMLphp.
 * It does so by settings hooks in MediaWiki which override the session handling system
 * and disable functionality that is redundant for federated logins.
 *
 * @file
 * @ingroup Extensions
 * @defgroup SimpleSamlAuth
 *
 * @license http://www.gnu.org/licenses/lgpl.html LGPL (GNU Lesser General Public License)
 * @copyright (C) 2015, Jørn Åne
 * @author Jørn Åne
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( "This is a MediaWiki extension, and must be run from within MediaWiki.\n" );
}

class SimpleSamlAuth {

	/** SAML Assertion Service */
	protected static $as;

	/** Whether $as is initialised */
	private static $initialised;

	/** Semaphore that will prevent any actions when set to false */
	private static $armed = true;

	/**
	 * Construct a new object and register it in $wgHooks.
	 * See README.md for possible values in $config.
	 *
	 * @param $config mixed[] Configuration settings for the SimpleSamlAuth extension.
	 *
	 * @return boolean
	 */
	private static function init() {
		global $wgSamlSspRoot;
		global $wgSamlAuthSource;
		global $wgSessionName;
		global $wgSessionsInMemcached;
		global $wgSessionsInObjectCache;

		if ( !self::$armed ) {
			return false;
		}
		if ( self::$initialised ) {
			return true;
		}

		if ( ( !isset( $wgSessionName ) || !$wgSessionName )
			&& ( !isset( $wgSessionsInObjectCache ) || !$wgSessionsInObjectCache )
			&& ( !isset( $wgSessionsInMemcached ) || !$wgSessionsInMemcached )
		) {
			ini_restore( 'session.name' );
			$wgSessionName = ini_get( 'session.name' );
		}

		// Load the SimpleSAMLphp service
		require_once rtrim( $wgSamlSspRoot, DIRECTORY_SEPARATOR ) .
			DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . '_autoload.php';

		self::$as = new SimpleSAML\Auth\Simple( $wgSamlAuthSource );

		self::$initialised = is_object( self::$as );

		return self::$initialised;
	}

	/**
	 * Will prevent any further action from this extension in the current request.
	 */
	private static function disarm() {
		self::$armed = false;
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
	public static function hookGetPreferences( $user, &$preferences ) {
		if ( !self::init() ) {
			return true;
		}
		global $wgSamlRequirement;
		global $wgSamlRealnameAttr;

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
	public static function hookSpecialPage_initList( &$pages ) {
		if ( !self::init() ) {
			return true;
		}
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
	 * which is a link/button which will redirect the user to SimpleSAMLphp to logon through SAML.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLoginForm
	 *
	 * @param $template UserloginTemplate
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error
	 */
	public static function hookLoginForm( &$template ) {
		if ( !self::init() ) {
			return true;
		}
		global $wgSamlRequirement;

		$url = self::$as->getLoginURL( Title::newMainPage()->getFullUrl() );

		if ( $wgSamlRequirement >= SAML_LOGIN_ONLY ) {
			self::$as->requireAuth( array(
				'ReturnTo' => Title::newMainPage()->getFullUrl(),
				'KeepPost' => false,
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
	 * This hook will execute the SimpleSAMLphp Single Sign Out feature,
	 * so that the logout is propagated to the IdP.
	 *
	 * @link http://www.mediawiki.org/wiki/Manual:Hooks/UserLogout
	 *
	 * @return boolean|string true on success, false on silent error, string on verbose error
	 */
	public static function hookUserLogout() {
		if ( !self::init() ) {
			return true;
		}
		global $wgSamlPostLogoutRedirect;
		global $wgRequest;

		if ( self::$as->isAuthenticated() ) {
			$returnTo = $wgRequest->getVal( 'returnto' );
			if ( isset( $wgSamlPostLogoutRedirect ) ) {
				self::$as->logout( $wgSamlPostLogoutRedirect );
			} elseif ( $returnTo ) {
				$page = Title::newFromText( $returnTo );
				if ( isset( $page ) ) {
					self::$as->logout( $page->getFullUrl() );
				}
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
		if ( !self::init() ) {
			return true;
		}
		global $wgSamlRequirement;
		global $wgSamlUsernameAttr;
		global $wgBlockDisablesLogin;
		global $wgContLang;

		if ( $result ) {
			// Another hook already logged in
			self::disarm();
			return true;
		}

		if ( $wgSamlRequirement >= SAML_REQUIRED ) {
			self::$as->requireAuth();
		}

		if ( self::$as->isAuthenticated() ) {
			$attr = self::$as->getAttributes();
			if ( !User::isUsableName( $wgContLang->ucfirst( reset( $attr[$wgSamlUsernameAttr] ) ) ) ) {
				return 'Illegal username: ' . reset( $attr[$wgSamlUsernameAttr] );
			}
			$loadError = self::loadUser( $user, $attr );
			if ( $loadError ) return $loadError;
			if ( $wgBlockDisablesLogin && $user->isBlocked() ) {
				$block = $user->getBlock();
				throw new UserBlockedError( $block );
			} else {
				// Set that we authenticated a user
				$result = true;
				// Some MediaWiki internals need a session
				// to function. Since we authenticated
				// from the outside, the MediaWiki session
				// might not have been initialized.
				if ( session_id() == '' ) {
					wfSetupSession();
				}
				// Apparently, nothing went wrong, and we
				// have a fancy user from a SAML assertion.
				// Success! Return true for no errors.
				return true;
			}
		}
		// Not authenticated, but no errors either
		// Return means success, $result is still false
		return true;
	}

	/**
	 * Replace the MediaWiki login/logout links with direct links to SimpleSAMLphp.
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
		if ( !self::init() ) {
			return true;
		}
		global $wgSamlRequirement;
		global $wgSamlPostLogoutRedirect;
		global $wgRequest;

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
				$returnTo = $wgRequest->getVal( 'returnto' );
				foreach ( array( 'login', 'anonlogin' ) as $link ) {
					if ( isset( $personal_urls[$link] ) ) {
						if ( $returnTo && Title::newFromText( $returnTo ) ) {
							$page = Title::newFromTextThrow( $returnTo );
							$url = $page->getFullUrl();
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
	 * Use this to do something completely different, after the basic globals have been set up,
	 * but before ordinary actions take place.
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
	public static function hookMediaWikiPerformAction( $output, $article, $title, $user, $req, $w ) {
		// Just running init will set the correct session cookie name.
		// This will prevent the session being initiated
		// with the wrong cookie name.
		self::init();
		return true;
	}

	/**
	 * Called to determine the class to handle the article rendering, based on title
	 *
	 * Reads the requested title.  If the title matches any title mentioned in $wgWhitelistRead,
	 * the value of $wgSamlRequirement will be lowered to be SAML_LOGIN_ONLY at most.
	 *
	 * The effect of this, is that the site admin can use SAML_REQUIRED but still open some
	 * pages to be queried by anonymous users.  This may be useful for allowing bots to read
	 * pages, for example.
	 *
	 * This hook is only called for articles, so it is not possible to whitelist special pages
	 * this way.
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/ArticleFromTitle
	 * @link https://www.mediawiki.org/wiki/Manual:$wgWhitelistRead
	 */
	public static function hookArticleFromTitle( &$title, &$article, $context ) {
		if ( !self::init() ) {
			return true;
		}

		global $wgWhitelistRead;
		global $wgSamlRequirement;

		if ( is_array( $wgWhitelistRead ) && in_array( $title, $wgWhitelistRead ) ) {
			$wgSamlRequirement = min( $wgSamlRequirement, SAML_LOGIN_ONLY );
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
	 * @param $user MediaWiki user that will be made to correspond to the SAML assertion
	 * @param $attr string[][] SAML attributes
	 *
	 * @return void $user is modified upon return
	 */
	protected static function loadUser( User $user, $attr ) {
		global $wgSamlCreateUser;
		global $wgSamlUsernameAttr;
		global $wgContLang;

		$username = $wgContLang->ucfirst( reset( $attr[$wgSamlUsernameAttr] ) );

		$id = User::idFromName( $username );
		if ( $id || $wgSamlCreateUser ) {
			if ( $id ) {
				$user->setId( $id );
				$user->loadFromId();
			} else {
				$user->setName( $username );
			}
			self::updateUser( $user, $attr );
			self::setGroups( $user );
		} else {
			return 'User "'
			     . htmlentities( reset( $attr[$wgSamlUsernameAttr] ) )
			     . "\" does not exist and \"\$wgSamlCreateUser\" flag is false.\n";
		}
	}

	/**
	 * Set users' fields from SAML attributes.
	 * If the user does not exist in the MediaWiki database,
	 * it is created. wgSamlCreateUser is not respected.
	 *
	 * @param User $user the user
	 * @param string[][] $attr SAML attributes
	 */
	protected static function updateUser( User $user, $attr ) {
		global $wgSamlRealnameAttr;
		global $wgSamlUsernameAttr;
		global $wgSamlMailAttr;
		global $wgContLang;
		global $wgVersion;

		$changed = false;
		if ( isset( $wgSamlRealnameAttr )
			&& isset( $attr[$wgSamlRealnameAttr] )
			&& $attr[$wgSamlRealnameAttr]
			&& $user->getRealName() !== reset( $attr[$wgSamlRealnameAttr] )
		) {
			$changed = true;
			$user->setRealName( reset( $attr[$wgSamlRealnameAttr] ) );
		}
		if ( isset( $wgSamlMailAttr )
			&& isset( $attr[$wgSamlMailAttr] )
			&& $attr[$wgSamlMailAttr]
			&& (
			!$user->isEmailConfirmed()
				|| $user->getEmail() !== reset( $attr[$wgSamlMailAttr] )
			)
		) {
			$changed = true;
			$user->setEmail( reset( $attr[$wgSamlMailAttr] ) );
			$user->confirmEmail();
		}
		if ( !$user->getId() ) {
			$user->setName( $wgContLang->ucfirst( reset( $attr[$wgSamlUsernameAttr] ) ) );
			if ( version_compare( $wgVersion, '1.26', '<=' ) ) {
				// MW 1.26 and below uses AuthPlugin, which wants setPassword first
				$user->setInternalPassword( null ); // prevent manual login until reset
				$user->addToDatabase();
			} else {
				// MW 1.27 and up uses AuthManager, which wants addToDatabase first
				$user->addToDatabase();
				$user->setInternalPassword( null ); // prevent manual login until reset
			}
		} elseif ( $changed ) {
			$user->saveSettings();
		}
	}

	/**
	 * Add groups based on two separate functions
	 *
	 * @param User $user add MediaWiki permissions to this user from the current SAML assertion
	 *
	 * @return void $user is modified on return
	 */
	protected static function setGroups( User $user ) {
		self::setGroupsStandard( $user );
		self::setGroupsRegex( $user );
	}

	/**
	 * Add groups based on the existence of attributes with specific matches in the SAML assertion.
	 *
	 * @param User $user add MediaWiki permissions to this user from the current SAML assertion
	 *
	 * @return void $user is modified on return
	 *
	 * @note $wgSamlGroupMap is in the form:
	 * $wgSamlGroupMap = [
	 *     'mediawikigroup' => [
	 *         'samlAttrName' => ['acceptable','saml','values'],
	 *         '__ADDONLY__' => true
	 *     ]
	 * ]
	 *
	 */
	protected static function setGroupsStandard ( User $user ) {
		global $wgSamlGroupMap;

		$allSamlAttrs = self::$as->getAttributes();

		foreach ( $wgSamlGroupMap as $mediawikiGroup => $rules ) {
			foreach ( $rules as $samlAttrName => $okValues ) {
				if ( ! isset( $allSamlAttrs[ $samlAttrName ] ) ) {
					continue;
				}

				// A SAML attribute could contain a list of values. Likewise,
				// we may want to specify a list of values that are acceptable
				// for that attribute. Thus, we have two lists and if there is
				// any intersection between them then the group should be
				// applied. If there is no intersection the group should be
				// removed.
				$intersections = array_intersect( $okValues, $allSamlAttrs[ $samlAttrName ] );

                                if ( isset( $wgSamlGroupMap[ $mediawikiGroup ][ '__ADDONLY__' ] ) ) {
                                        $addOnly = $wgSamlGroupMap[ $mediawikiGroup ][ '__ADDONLY__' ];
                                }
                                else {
                                        $addOnly = false;
                                }

				if ( count( $intersections ) > 0 ) {
					$user->addGroup( $mediawikiGroup );

					// User allowed into group. Break out of this foreach and
					// proceed to the next mediawikiGroup
					break;
				}
				else if ( ! $addOnly ) {
					$user->removeGroup( $mediawikiGroup );
				}
			}
		}
	}

	/**
	 * Add groups based on regex matches to attributes in the SAML assertion.
	 *
	 * @param User $user add MediaWiki permissions to this user from the current SAML assertion
	 *
	 * @return void $user is modified on return
	 *
	 * @note $wgSamlGroupMapRegex is in the form:
	 * $wgSamlGroupMapRegex = [
	 *     'mediawikigroup' => [
	 *         'samlAttrName' => "/SomeRegex/",
	 *         '__ADDONLY__' => true
	 *     ]
	 * ]
	 *
	 */
	protected static function setGroupsRegex ( User $user ) {
		global $wgSamlGroupMapRegex;

		$allSamlAttrs = self::$as->getAttributes();

		foreach ( $wgSamlGroupMapRegex as $mediawikiGroup => $rules ) {
			foreach ( $rules as $samlAttrName => $regex ) {
				if ( ! isset( $allSamlAttrs[ $samlAttrName ] ) ) {
					continue;
				}

				// A SAML attribute may have many values. Perform regex match
				// against all of them and keep those that match.
				$matchingValsFromAttr = preg_grep( $regex, $allSamlAttrs[ $samlAttrName ] );

				if ( isset( $wgSamlGroupMapRegex[ $mediawikiGroup ][ '__ADDONLY__' ] ) ) {
					$addOnly = $wgSamlGroupMapRegex[ $mediawikiGroup ][ '__ADDONLY__' ];
				}
				else {
					$addOnly = false;
				}

				if ( count( $matchingValsFromAttr ) > 0 ) {
					$user->addGroup( $mediawikiGroup );

					// User allowed into group. Break out of this foreach and
					// proceed to the next mediawikiGroup
					break;
				}
				else if ( ! $addOnly ) {
					$user->removeGroup( $mediawikiGroup );
				}
			}
		}
	}
}
