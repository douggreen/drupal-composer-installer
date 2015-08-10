<?php

namespace Drupal\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\FileSystem;

class DrupalInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->installer = new DrupalInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);

        $extra = $composer->getPackage()->getExtra();
        $extra += array(
            'drupal-custom' => array(),
            'drupal-root' => 'core',
        );

        $this->drupalRoot = $extra['drupal-root'];

        $this->drupalCustom = array_unique(array_merge(array(
            $this->drupalRoot . '/sites/all/modules/custom',
            $this->drupalRoot . '/sites/all/themes/custom',
        ), $extra['drupal-custom']));

        $this->tmp = array();
    }

    public static function getSubscribedEvents() {
        return array(
            PackageEvents::PRE_PACKAGE_INSTALL => 'before',
            PackageEvents::PRE_PACKAGE_UPDATE => 'before',
            PackageEvents::POST_PACKAGE_INSTALL => 'after',
            PackageEvents::POST_PACKAGE_UPDATE => 'after',
        );
    }

    function before(PackageEvent $event) {
        $io = $event->getIO();

        if ($this->getPackageName($event, $io) === 'drupal/drupal') {
            $this->beforeDrupalSaveCustom($event, $io);
        }
    }

    protected function beforeDrupalSaveCustom(PackageEvent $event, IOInterface $io) {
        // Change permissions for a better outcome when deleting existing sites,
        // since Drupal changes the permissions on these directories.
        $sitesDir = $this->drupalRoot . '/sites';
        $scanFiles = scandir($sitesDir);
        foreach ($scanFiles as $partialPath) {
            if ($partialPath != '.' && $partialPath != '..') {
                $filePath = "$sitesDir/$partialPath";
                if (is_dir($filePath)) {
                    @chmod($filePath, 0755);
                    @chmod("$filePath/settings.php", 0644);
                }
            }
        }

        $file = new FileSystem();

        foreach ($this->drupalCustom as $path) {
            if (!file_exists($path)) {
                continue;
            }

            if (!isset($this->tmpdir)) {
                $this->tmpdir = uniqid('/tmp/dci') . '.bak';
                if ($io->isVerbose()) {
                    $io->write("<info>Ensure $this->tmpdir</info>");
                }
                $file->ensureDirectoryExists($this->tmpdir);
            }

            $basename = basename($path);
            $tmpfile = $this->tmpdir . '/' . $basename;
            if (file_exists($tmpfile)) {
                $tmpfile = $this->tmpdir . '/' . md5($path);
                if ($io->isVerbose()) {
                    $io->write("<info>Ensure $tmpfile</info>");
                }
                $file->ensureDirectoryExists($tmpfile);
                $tmpfile .= '/' . $basename;
            }

            $io->write("<info>Save $path to $tmpfile</info>");
            $file->rename($path, $tmpfile);
            $this->tmp[$path] = $tmpfile;
        }
    }

    function after(PackageEvent $event) {
        if (!isset($this->tmpdir)) {
            return;
        }

        $io = $event->getIO();
        $package = $this->getPackage($event, $io);
        $packageName = $this->getPackageInterfaceName($package);
        $packageType = $package->getType();

        if ($packageName === 'drupal/drupal') {
            $this->afterDrupalRestoreCustom($event, $io);
        }
        elseif ($packageType === 'drupal-module' || $packageType === 'drupal_theme') {
            $this->afterDrupalRewriteInfo($event, $io, $package);
        }
    }

    protected function afterDrupalRestoreCustom(PackageEvent $event, IOInterface $io) {
        $file = new FileSystem();

        foreach ($this->tmp as $path => $tmpfile) {
            $io->write("<info>Restore $path from $tmpfile</info>");
            if (file_exists($path) && is_dir($path)) {
              $file->removeDirectory($path);
            }
            $file->rename($tmpfile, $path);
        }

        $file->removeDirectory($this->tmpdir);
    }

    protected function afterDrupalRewriteInfo(PackageEvent $event, IOInterface $io, PackageInterface $package) {
        $packageVersion = $package->getVersion();
        $packageName = $this->getPackageInterfaceName($package);
        list($vendor, $project) = explode('/', $packageName);

        $packagePath = $this->installer->getPackageBasePath($package);
        $scanFiles = scandir($packagePath);
        foreach ($scanFiles as $partialPath) {
            if (substr($partialPath, -5) === '.info') {
                $filePath = "$packagePath/$partialPath";
                $info = file($filePath);
                if (!preg_grep('/version\s*=/', $info)) {
                    $moreInfo = "\n"
                        . "; Information added by drupal-composer-installer packaging script on " . date('Y-m-d') . "\n"
                        . "version = \"$packageVersion\"\n"
                        . "project = \"$project\"\n"
                        . "datetimestamp = \"" . time() . "\"\n";
                    file_put_contents($filePath, $moreInfo, FILE_APPEND);

                    $io->write("<info>Rewrite $filePath project=$project, version=$packageVersion</info>");
                }
            }
        }
    }

    protected function getPackage(PackageEvent $event, IOInterface $io) {
        $operation = $event->getOperation();
        foreach (array('getPackage', 'getTargetPackage') as $method) {
            if (method_exists($operation, $method)) {
                return $operation->$method();
            }
        }
        return NULL;
    }

    protected function getPackageName(PackageEvent $event, IOInterface $io) {
        $package = $this->getPackage($event, $io);
        return $this->getPackageInterfaceName($package);
    }

    protected function getPackageInterfaceName(PackageInterface $package) {
        return $package ? $package->getName() : 'none/none';
    }
}
