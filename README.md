# Drupal installer

Drupal composer installer plugin

* Installs Drupal core into drupal-root.
* Installs Drupal modules into {drupal-root}/sites/all/modules/{dir}.
* Installs libraries into {drupal-root}/sites/all/libraries.

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

### drupal-libraries - array of packages to install into {drupal-root}/sites/all/libraries.

```
  "extra": {
    "drupal-libraries": {
      "harvesthq/chosen",
      "jquery/*"
    },
  },
```

The value ```ckeditor/ckeditor``` is implied by default.

### drupal-modules - map of packages to directories.

* drupal/* : contrib, by default all drupal modules are installed in {drupal-root}/sites/all/modules/contrib
or {drupal-root}/sites/all/modules/custom. Additional directories can be specified 

```
  "extra": {
    "drupal-modules": {
      "vendor/*": "vendor",
      "vendor/name": "sandbox"
    },
  },
```

The value ```"drupal/*": "contrib"``` is implied by default but can be overridden.
