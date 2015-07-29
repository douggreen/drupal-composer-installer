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
        if ($this->getPackageName($event) !== 'drupal/drupal') {
            return;
        }

        $io = $event->getIO();

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
        if ($this->getPackageName($event) !== 'drupal/drupal' || !isset($this->tmpdir)) {
            return;
        }

        $io = $event->getIO();

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

    function getPackageName(PackageEvent $event) {
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
