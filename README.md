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
    "drupal/composer-installer": "~2.0"
  }
```

You'll also want to add a packageist as follows (that is, until drupal.org implements it's own):

```
  "repositories": [
    {
      "type": "composer",
      "url": "http://drupal-packagist.webflo.io/"
    }
  ]
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
  }
```

### drupal-libraries - map of packages to install into {drupal-root}/sites/all/libraries.

The package is the key name. Any value specifies a directory name under sites/all/libraries.

```
  "extra": {
    "drupal-libraries": {
      "harvesthq/chosen" : "",
      "desandro/imagesloaded" : "jquery-imagesloaded"
    }
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
    }
  }
```

The value ```"drupal/*": "contrib"``` is implied by default but can be overridden.

### drupal-custom - array of custom paths.

This is array of custom code paths that should be saved before drupal/drupal is installed and restored after it is installed.

```
  "extra": {
    "drupal-custom": [
      "core/sites/all/themes/mytheme"
    ]
  }
```

The values ```sites/all/modules/custom``` and ```sites/all/themes/custom``` are implied by default and do not
need to be listed.

### git - optional

Optionally, control the use of git.

Git is used in two ways during installation.

Git is used on download and installation.
When installing from a git repository a local .git directory is left in the installation directory.
This subdirectory is needed for git update to work properly.
However, many projects using this installer, wish to check the installed downloaded projects into their own git repository, and may not wish to use subtrees.
To avoid using subtree's, set extra.git.path to any alternative path, such as ".git-drupal" and then add that same path to the project's .gitignore.
Then when downloading from a git repository, the .git directory will be restored to .git before doing and update and renamed after the installation.

```
  "extra": {
    "git": {
      "path": ".git-drupal"
    }
  }
```

Git can also be used to save the downloaded and installed subprojects into a local project git.

Set extra.git.commit to enable git commit's after each installation and patch.
By default this is 0

Set extra.git.commit-prefix to add a prefix to each commit message.
By default this is empty.

```
  "extra": {
    "git": {
      "commit": 1,
      "commit-prefix": "Drupal composer installer: "
    }
  }
```

The commit prefix can be overridden using the COMPOSER_GIT_COMMIT_PREFIX environment variable.

```
COMPOSER_GIT_COMMIT_PREFIX="Ticket #1234: " composer.phar install
```

Set extra.git.base-branch to force a git checkout of the base branch before creating new branches.
And then set extra.git.branch-prefix to force the creation of new branches for each project.
By default the base-branch is empty, meaning any commit happens only to the current branch.
But if base-branch and branch-prefix are set, then each installed project is put into a new branch.

```
  "extra": {
    "git": {
      "base-branch": "master",
      "branch-prefix": "composer-"
    }
  }
```

Set extra.git.auto-push to enable a git push of each branch.
And set extra.git.remote to define which remote the branch is pushed to.

```
  "extra": {
    "git": {
      "auto-push": 0,
      "remote": "origin"
    }
  }
```

Alternatively, the remote can also be set using the COMPOSER_GIT_REMOTE environment variable.

```
COMPOSER_GIT_REMOTE=upstream composer.phar install
```

Git branch names append the project version number.
Git branch names also end with "-SA" the difference between the old version and the current version includes a 'Security advisory'.

Set extra.git.security to force only the saving of security advisory's.

```
  "extra": {
    "git": {
      "base-branch": "master",
      "branch-prefix": "composer-",
      "security": 1
    }
  }
```

Alternatively, this can also be set using the COMPOSER_GIT_SECURITY environment variable.

```
COMPOSER_GIT_SECURITY=1 composer.phar install
```

### patches - optional

The installer works well with https://github.com/cweagans/composer-patches,
however you must have https://github.com/cweagans/composer-patches/pull/15,
which at the moment means using the dev-master version.

```
  "require": {
    "cweagans/composer-patches": "dev-master",
    "drupal/composer-installer": "~2.0"
  }
```
