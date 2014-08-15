<?php
/**
 * SimpleSamlAuth - LGPL 3.0 licensed
 * Copyright (C) 2014  Yørn de Jong
 *
 * SAML authentication MediaWiki extension using SimpleSamlPhp.
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

$wgExtensionMessagesFiles['SimpleSamlAuth'] =
	__DIR__ . DIRECTORY_SEPARATOR . 'SimpleSamlAuth.i18n.php';
$wgAutoloadClasses['SimpleSamlAuth'] =
	__DIR__ . DIRECTORY_SEPARATOR . 'SimpleSamlAuth.class.php';

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'SimpleSamlAuth',
	'version' => 'GIT-master',
	'author' => 'Yørn de Jong',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SimpleSamlAuth',
	'descriptionmsg' => 'simplesamlauth-desc'
);

$wgHooks['UserLoadFromSession'][]    = 'SimpleSamlAuth::hookLoadSession';
$wgHooks['GetPreferences'][]         = 'SimpleSamlAuth::hookGetPreferences';
$wgHooks['SpecialPage_initList'][]   = 'SimpleSamlAuth::hookSpecialPage_initList';
$wgHooks['UserLoginForm'][]          = 'SimpleSamlAuth::hookLoginForm';
$wgHooks['UserLogoutComplete'][]     = 'SimpleSamlAuth::hookUserLogout';
$wgHooks['PersonalUrls'][]           = 'SimpleSamlAuth::hookPersonalUrls';
$wgHooks['MediaWikiPerformAction'][] = 'SimpleSamlAuth::hookMediaWikiPerformAction';

define('SAML_OPTIONAL', 0);
define('SAML_LOGIN_ONLY', 1);
define('SAML_REQUIRED', 2);

$wgSamlRequirement = SAML_OPTIONAL;
$wgSamlCreateUser = false;
$wgSamlConfirmMail = false;

$wgSamlAuthSource = 'default-sp';
$wgSamlSspRoot = rtrim(__DIR__, DIRECTORY_SEPARATOR)
               . DIRECTORY_SEPARATOR
               . 'simplesamlphp'
               . DIRECTORY_SEPARATOR
               ;
$wgSamlPostLogoutRedirect = null;

$wgSamlGroupMap = array(
	'sysop' => array(
		'groups' => array('admin'),
	),
);

$wgSamlUsernameAttr = 'uid';
$wgSamlRealnameAttr = 'cn';
$wgSamlMailAttr = 'mail';
