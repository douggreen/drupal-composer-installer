# Drupal installer

Drupal composer installer plugin

* Installs Drupal core into drupal-root.
* Installs Drupal modules into {drupal-root}/sites/all/modules/{dir}.
* Installs libraries into {drupal-root}/sites/all/libraries.

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
