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

        $this->cached = array();
    }

    /**
     * {@inheritDoc}
     */
    public function getPackageBasePath(PackageInterface $package)
    {
        $packageName = strtolower($package->getName());

        if (isset($this->cached[$packageName])) {
            return $this->cached[$packageName];
        }

        if ($packageName === 'drupal/drupal') {
            $path = $this->drupalRoot;
        }
        else {
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
        }
        if ($path) {
            $this->io->write("<info>Installing $packageName in $path.</info>");
        }
        else {
            $path = parent::getPackageBasePath($package);
        }

        $this->cached[$packageName] = $path;

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
