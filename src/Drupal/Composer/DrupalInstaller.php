<?php

namespace Drupal\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;

class DrupalInstaller extends LibraryInstaller
{
    public function __construct(IOInterface $io, Composer $composer)
    {
        parent::__construct($io, $composer);

        $extra = $this->composer->getPackage()->getExtra();
        $extra += array(
            'drupal-libraries' => array(),
            'drupal-modules' => array(),
            'drupal-root' => 'core',
        );

        $default_libraries = array(
            'ckeditor/ckeditor',
        );
        $libraries = array_map('strtolower', array_unique(array_merge($default_libraries, $extra['drupal-libraries'])));
        $this->drupalLibraries = array_combine($libraries, $libraries);

        $this->drupalModules = $extra['drupal-modules'] + array(
          'drupal/*' => 'contrib',
        );

        $this->drupalRoot = $extra['drupal-root'];
    }

    /**
     * {@inheritDoc}
     */
    public function getPackageBasePath(PackageInterface $package)
    {
      $packageName = strtolower($package->getName());

      if ($packageName === 'drupal/drupal') {
          return $this->drupalRoot;
      }

      list($vendor, $name) = explode('/', $packageName);

      $path = '';
      if ($package->getType() === 'drupal-module') {
          $path = $this->drupalRoot . '/sites/all/modules/';
          if (isset($this->drupalModules[$packageName])) {
              $path .= $this->drupalModules[$packageName];
          }
          elseif (isset($this->drupalModules["$vendor/*"])) {
              $path .= $this->drupalModules["$vendor/*"];
          }
          else {
              $path .= "custom";
          }
      }
      if (isset($this->drupalLibraries[$packageName]) || isset($this->drupalLibraries["$vendor/*"])) {
          $path = $this->drupalRoot . '/sites/all/libraries';
      }
      if ($path) {
          $path .= '/' . $name;
      }
      else {
          $path = parent::getPackageBasePath($package);
      }

      return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return TRUE;
    }
}
