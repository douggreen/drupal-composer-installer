<?php

namespace Drupal\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\FileSystem;

class DrupalInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new DrupalInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);

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

        if ($this->getPackageName($event, $io) !== 'drupal/drupal') {
            return;
        }

        // Change permissions for a better outcome when deleting existing sites,
        // since Drupal changes the permissions on these directories.
        $sitesDir = $this->drupalRoot . '/sites';
        $sites = scandir($sitesDir);
        foreach ($sites as $site) {
            if ($site != '.' && $site != '..') {
                $siteDir = "$sitesDir/$site";
                if (is_dir($siteDir)) {
                    @chmod($siteDir, 0755);
                    @chmod("$siteDir/settings.php", 0644);
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
        $io = $event->getIO();

        if ($this->getPackageName($event, $io) !== 'drupal/drupal' || !isset($this->tmpdir)) {
            return;
        }

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

    function getPackageName(PackageEvent $event, IOInterface $io) {
        $name = 'none/none';
        $operation = $event->getOperation();
        foreach (array('getPackage', 'getTargetPackage') as $method) {
            if (method_exists($operation, $method)) {
                $name = $operation->$method()->getName();
                break;
            }
        }
        return $name;
    }
}
