<?php

namespace Drupal\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;

class DrupalInstaller extends LibraryInstaller {

    /**
     * @var array $cached
     */
    protected $cached = array();

    /**
     * @var array $options
     */
    protected $options;

    /**
     * @var array $defaultOptions
     */
    protected $defaultOptions = array(
        'drupal-libraries' => array(),
        'drupal-modules' => array(),
        'drupal-themes' => array(),
        'drupal-root' => 'core',
        'drupal-sites' => 'sites',
        'drupal-site' => 'all',
    );

    /**
     * Initializes options.
     *
     * Note: This is not done during __construct to work
     *       with wikimedia/composer-merge-plugin.
     */
    protected function getOptions() {
        $extra = $this->composer->getPackage()->getExtra();

        $extra += $this->defaultOptions;

        $options = array();
        $options['drupal-libraries'] = $extra['drupal-libraries'] + array(
            'ckeditor/ckeditor' => "",
        );

        $options['drupal-modules'] = $extra['drupal-modules'] + array(
            'drupal/*' => 'contrib',
        );

        $options['drupal-themes'] = $extra['drupal-themes'] + array(
          'drupal/*' => 'contrib',
        );

        $options['drupal-root'] = $extra['drupal-root'];
        $options['drupal-sites'] = $extra['drupal-sites'];
        $options['drupal-site'] = $extra['drupal-site'];

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package) {
        // Early return for composer-plugin's as those are loaded really early
        // in the bootstrap process.
        if ($package->getType() === "composer-plugin") {
          return parent::getInstallPath($package);
        }

        $packageName = strtolower($package->getName());

        if (isset($this->cached[$packageName])) {
            return $this->cached[$packageName];
        }

        // Lazily initialize options.
        if (!isset($this->options)) {
          $this->options = $this->getOptions();
        }

        if ($packageName === 'drupal/drupal') {
            $path = $this->options['drupal-root'];
        }
        else {
            list($vendor, $name) = explode('/', $packageName);

            $basePath = $this->options['drupal-root'] . '/' . $this->options['drupal-sites'] . '/' . $this->options['drupal-site'];
            $path = '';
            foreach (array('module' => 'drupal-modules', 'theme' => 'drupal-themes') as $type => $drupalType) {
                if ($package->getType() === "drupal-$type") {
                    $subdir = "project";
                    foreach (array($packageName, "$vendor/*") as $key) {
                        if (isset($this->options[$drupalType][$key])) {
                            $subdir = $this->options[$drupalType][$key];
                        }
                    }
                    $path = "$basePath/{$type}s/$subdir/$name";
                }
            }
            if (!$path) {
                foreach (array($packageName, "$vendor/*") as $key) {
                    if (isset($this->options['drupal-libraries'][$key])) {
                        $path = "$basePath/libraries/";
                        $path .= empty($this->options['drupal-libraries'][$key]) ? $name : $this->options['drupal-libraries'][$key];
                    }
                }
            }
        }
        if ($path) {
            $this->io->write("Installing <info>$packageName</info> in <info>$path.</info>");
        }
        else {
            $path = parent::getInstallPath($package);
        }

        $this->cached[$packageName] = $path;

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType) {
        switch ($packageType) {
          case 'metapackage':
            return FALSE; // use default metapackage handling
          default:
            // @todo Actually check! We should really only be handling library-ish types!
            return TRUE;
       }
   }
}
