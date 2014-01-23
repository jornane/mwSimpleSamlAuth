# SAML plugin for Mediawiki
Requires [simpleSamlPhp](http://simplesamlphp.org) and PHP >= 5.3. Tested on Mediawiki 1.21.

## Installation
* Create a directory **SimpleSamlAuth** in the *extensions* directory and copy **SimpleSamlAuth.php** there.
* Install simpleSamlPhp. For easy deployment, this plugin will look for a directory *simplesamlphp* in the same directory where SimpleSamlAuth.php is installed, but it is recommended that you put it somewhere else instead and configure this location in the config. *See the simpleSamlPhp documentation for instructions on how to install simpleSamlPhp.*
* Configure simpleSamlPhp so that it can authenticate against your authentication source. *Again see the simpleSamlPhp documentation on how to do this.*
* Add the following lines to your **LocalSettings.php** in your Mediawiki installation:

```php
require_once "$IP/extensions/SimpleSamlAuth/SimpleSamlAuth.php";
SimpleSamlAuth::registerHooks(array(
	// config goes here
));
```

## Configuration
The configuration is an indexed array, with the following possible keys (other keys are ignored):
### authSource
*(default value: __default-sp__)*  
The name of the desired authSource in simpleSamlPhp. For SAML authentication, this is default-sp.
### usernameAttr
*(default value: __uid__)*  
The name of the attribute which contains the username. Use the **Test configured authentication sources** feature under **Authentication** in simpleSamlPhp if you are unsure.
### realnameAttr
*(default value: __cn__)*  
The name of the attribute which contains the real name of the user.
### mailAttr
*(default value: __mail__)*  
The name of the attribute which contains the e-mail address of the user.
### groupMap
*(default: if the __groups__ attribute contains __admin__, the user is added to the __sysop__ and __bureaucrat__ Mediawiki groups)*  
Rules to add the user to Mediawiki groups, based on SAML attributes.
Example: (this is the default)

```php
'groupMap' => array (
	'sysop' => array (
		'groups' => array('admin'),
	),
	'bureaucrat' => array (
		'groups' => array('admin'),
	),
```
### sspRoot
*(default: simplesamlphp directory alongside SimpleSamlAuth.php)*  
The location of the simpleSamlPhp installation.
### autocreate
*(default value: __false__)*  
Whether users are created if they don't exist in Mediawiki yet.
### readHook
*(default value: __false__)*  
Redirect users to the login page if they experience a permission error which prevents reading the page. This is only useful on wikis that are configured against anonymous read access.
### postLogoutRedirect
*(default value: current page or main page)*  
Redirect users to this URL after they logout.