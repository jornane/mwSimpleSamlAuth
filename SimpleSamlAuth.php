<?php
/**
 * SimpleSamlAuth - GPL 3.0 licensed
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

$wgExtensionMessagesFiles['SimpleSamlAuth'] = __DIR__ . DIRECTORY_SEPARATOR . 'SimpleSamlAuth.i18n.php';
$wgAutoloadClasses['SimpleSamlAuth'] = __DIR__ . DIRECTORY_SEPARATOR . 'SimpleSamlAuth.class.php';

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'SimpleSamlAuth',
	'version' => 'GIT-master',
	'author' => 'Yørn de Jong',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SimpleSamlAuth',
	'descriptionmsg' => 'simplesamlauth-desc'
);

