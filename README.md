# Drupal installer

Drupal composer installer plugin

* Installs Drupal core into drupal-root.
* Installs Drupal modules into {drupal-root}/sites/all/modules/{dir}.
* Installs libraries into {drupal-root}/sites/all/libraries.
* Saves and restores custom code around drupal/drupal installation.

## Usage

To use this installer in your project build, in your composer.json add

```
  "repositories": [
    {
      "type": "git",
      "url": "git@github.com:douggreen/drupal-composer-installer.git"
    }
  ],
  "require": {
    "drupal/composer-installer": "~1.0"
  }
```

You'll also want to add a packageist as follows (that is, until drupal.org implements it's own):

```
  "repositories": [
    {
      "type": "composer",
      "url": "http://drupal-packagist.webflo.io/"
    }
  ],
```

Your somewhat complete composer.json might look like:

```
{
  "name": "Example project",
  "repositories": [
    {
      "type": "composer",
      "url": "http://drupal-packagist.webflo.io/"
    },
    {
      "type": "git",
      "url": "git@github.com:douggreen/drupal-composer-installer.git"
    }
  ],
  "require": {
    "composer/installers": "*",
    "drupal/composer-installer": "~1.0",
    "drupal/drupal": "~7.38"
  },
  "extra": {
    "drupal-root": "docroot"
  }
}
```

## Options

### drupal-root - the directory to install drupal into, defaults to 'core'

```
  "extra": {
    "drupal-root": "docroot"
  },
```

### drupal-libraries - map of packages to install into {drupal-root}/sites/all/libraries.

The package is the key name. Any value specifies a directory name under sites/all/libraries.

```
  "extra": {
    "drupal-libraries": {
      "harvesthq/chosen" : "",
      "desandro/imagesloaded" : "jquery-imagesloaded",
    },
  },
```

The value ```ckeditor/ckeditor``` is implied by default.

### drupal-modules - map of packages to directories.

* drupal/* : contrib, by default all drupal modules are installed in {drupal-root}/sites/all/modules/contrib
or {drupal-root}/sites/all/modules/project. Additional directories can be specified.

```
  "extra": {
    "drupal-modules": {
      "vendor/*": "vendor",
      "vendor/name": "sandbox"
    },
  },
```

The value ```"drupal/*": "contrib"``` is implied by default but can be overridden.

### drupal-custom - array of custom paths.

This is array of custom code paths that should be saved before drupal/drupal is installed and restored after it is installed.

```
  "extra": {
    "drupal-custom": [
      "core/sites/all/themes/mytheme"
    ],
  },
```

The values ```sites/all/modules/custom``` and ```sites/all/themes/custom``` are implied by default and do not
need to be listed.

### no-git-dir - optional

If set, any .git directory that is downloaded from "git" "repositories" is removed.
Set this to avoid git subprojects.
