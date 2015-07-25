<?php

namespace Drupal\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class DrupalInstallerPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new DrupalInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}
