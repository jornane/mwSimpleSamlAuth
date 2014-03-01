# SAML plugin for Mediawiki

## Glossary
* **SimpleSamlAuth** This extension, uses *SimpleSamlPhp* to allow SAML login in *MediaWiki*.
* **SimpleSamlPhp** Open source lightweight SAML implementation by UNINETT.
* **MediaWiki** Open source Wiki software.

## Requirements
* [SimpleSamlPhp](//simplesamlphp.org) (tested on 1.11 and newer)
* [MediaWiki](//mediawiki.org) (1.15 works, 1.16 or newer required for some features)

## Preparation
* Install SimpleSamlPhp on the same domain as your MediaWiki installation.
* In SimpleSamlPhp, use the *Authentication* -> *Test configured authentication sources* feature to ensure that authentication works. Also make sure that the attributes make sense. 

You may keep the attributes page open for later reference, for filling out `$wgSamlUsernameAttr`, `$wgSamlRealnameAttr` and `$wgSamlMailAttr`.


If you encounter problems during the preparation, please [look here](http://simplesamlphp.org/support) for support. Only report bugs for SimpleSamlAuth when the preparation steps work for you.

## Installation
* Clone this repository into your MediaWikis *extensions* directory, and call it **SimpleSamlAuth**.

```bash
git clone git@github.com:yorn/mwSimpleSamlAuth.git SimpleSamlAuth
```

* Add the following lines to **LocalSettings.php** in your Mediawiki installation:

```php
require_once "$IP/extensions/SimpleSamlAuth/SimpleSamlAuth.php";

// SAML_OPTIONAL // SAML_LOGIN_ONLY // SAML_REQUIRED //
$wgSamlRequirement = SAML_OPTIONAL;
// Should users be created if they don't exist in the database yet?
$wgSamlCreateUser = false;
// Auto confirm e-mail for SAML users?
// Use together with $wgEmailAuthentication
$wgSamlConfirmMail = false;

// SAML attributes
$wgSamlUsernameAttr = 'uid';
$wgSamlRealnameAttr = 'cn';
$wgSamlMailAttr = 'mail';

// SimpleSamlPhp settings
$wgSamlAuthSource = 'default-sp';
$wgSamlPostLogoutRedirect = NULL;
$wgSamlSspRoot = rtrim(__DIR__, DIRECTORY_SEPARATOR)
               . DIRECTORY_SEPARATOR
               . 'simplesamlphp'
               . DIRECTORY_SEPARATOR
               ;

// Array: [MediaWiki group][SAML attribute name][SAML expected value]
// If the SAML assertion matches, the user is added to the Mediawiki group
$wgSamlGroupMap = array(
	'sysop' => array(
		'groups' => array('admin'),
	),
);
```

## Configuration
Modify the variables starting with *$wgSaml* to configure the extension. Some important variables:

### $wgSamlRequirement
This variable tells the extension how MediaWiki should behave. There are three options:

|                                    | optional | login_only | required |
|-----------------------------------:|:--------:|:----------:|:--------:|
|           Allow login through SAML |    ✓     |     ✓      |    ✓     |
| Update user's real name and e-mail |    ✓     |     ✓      |    ✓     |
| Prevent creation of local accounts |          |     ✓      |    ✓     |
|   Prevent login with local account |          |     ✓      |    ✓     |
|         Prevent anonymous browsing |          |            |    ✓     |
|       Redirect to login immediatly |          |            |    ✓     |

You can still use the [MediaWiki methods for preventing access](http://www.mediawiki.org/wiki/Manual:Preventing_access) to block certain actions, even if SimpleSamlAuth won't block them. The only exception is that  `$wgSamlCreateUser = true` will have priority over `$wgGroupPermissions['*']['createaccount'] = false`.

### $wgSamlConfirmMail
This variable tells the extension that the e-mail address that is set from the SAML assertion must be marked as confirmed. Normally, e-mail confirmation happens by sending an e-mail to the user which contains a link that must be clicked to proof the user really owns the address, but this option allows users logging in through SAML to skip this step, while local users still have to confirm by clicking a link in an e-mail.

This option doesn't make much sense outside `SAML_OPTIONAL`, because when every user must log in through SAML, it's easier to just set `$wgEmailAuthentication = false`.

### $wgSamlAuthSource
This is the name of the AuthSource you configured in SimpleSamlPhp. You can easily find it by going to the SimpleSamlPhp installation page and going to *Authentication* -> *Test configured authentication sources*. The word you have to click there is the name of your AuthSource. For SAML sessions, the standard preconfigured name in SimpleSamlPhp is `default-sp` and this is also what SimpleSamlAuth will guess if you omit the variable.

### $wgSamlPostLogoutRedirect
This is an URL where users are redirected when they log out from the MediaWiki installation. Generally, for a `SAML_REQUIRED` setup you want to set this to a landing page (intranet, for example). For any other setup, you may not want to set this to allow a user to continue browsing the Wiki anonymously when logging out.

### $wgSamlGroupMap
This is a list of rules used to add users to MediaWiki groups based on their SAML attributes. It is an array of three layers deep:

* Name of the MediaWiki group (for example `sysop`)
* Name of a SAML attribute (for example `groups`)
* Possible value for the SAML attribute (for example `admin`)

```php
$wgSamlGroupMap = array(
	'sysop' => array(
		'groups' => array('admin'),
	),
);
```
An array as illustrated here will add users to the `sysop` MediaWiki group, if they have a SAML attribute named `groups` with at least a value `admin`. If you want more fine-grained control, look at the [SimpleSamlPhp role module](https://github.com/yorn/sspmod_role).

### SimpleSamlAuth::preload();
Add this line if you need/want to run SimpleSamlPhp with `'store.type' => 'phpsession'`. This line should be added to **LocalSettings.php** *after* the variable assignments.

The `preload()` function will start SimpleSamlPhp rightaway, allowing it to read session information before MediaWiki does.

## Known Issues
### [State Information Lost](https://code.google.com/p/simplesamlphp/wiki/LostState)
This problem is caused by MediaWiki and SimpleSamlPhp fighting over the PHP Session system, and SimpleSamlPhp losing. There are two ways to solve this problem:

* In `config.php` in your SimpleSamlPhp installation, change `'store.type' => 'phpsession'` to another backend. Memcache is very easy to set up.
* Add `SimpleSamlAuth::preload();` at the *end* of **LocalSettings.php**. This will give SimpleSamlPhp an advantage reading the session information.

### SAML users can edit their e-mail address
Extensions can only disable preferences [since MediaWiki 1.16](http://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences).
Ubuntu 12.04 LTS comes with MediaWiki 1.15.
[WikiMedia recommends against using the Ubuntu-provided version of MediaWiki.](http://www.mediawiki.org/wiki/Manual:Running_MediaWiki_on_Ubuntu)

### E-mail addresses are not automatically confirmed
SimpleSamlAuth will *only* confirm e-mail addresses that it has set itself.
Make sure that you have configured `$wgSamlMailAttr` correctly.

### SAML users overwrite MediaWiki users / SAML users can reset their password and become a local user
There is not really a difference between local accounts and remote accounts in Mediawiki. [There has been an idea to implement this](http://www.mediawiki.org/wiki/ExternalAuth), but it looks like it's dead now.

Upon SAML retrieval of a SAML assertion, SimpleSamlAuth simply finds a local MediaWiki user with a username roughly equal to the value of the username attribute; if it doesn't exist, and if `$wgSamlCreateUser` is set, the user is created. This newly created user will have no password, but will be able to reset its password if a valid e-mail address has been set.

### Other issue?
Please report it on the project's [GitHub issues page](https://github.com/yorn/mwSimpleSamlAuth/issues).
