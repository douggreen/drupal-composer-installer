<?php

namespace Drupal\Composer;

use Composer\Script\Event;
use Composer\Script\ScriptEvents;

trait DrupalInstallerMergePluginTrait {

    /**
     * Integrates with the composer-merge plugin.
     *
     * @return array
     *   An array with the extra information of the root composer.json and any
     *   included composer.json files.
     */
    protected function getRootPackageExtra() {
        /** @var Composer\Package\RootPackageInterface $root */
        $root = $this->composer->getPackage();

        // Call composer-merge-plugin if it exists.
        $plugins = $this->composer->getPluginManager()->getPlugins();

        $mergePlugin = NULL;
        foreach ($plugins as $plugin) {
            if ($plugin instanceof \Wikimedia\Composer\MergePlugin) {
                $mergePlugin = $plugin;
                break;
            }
        }
        if (isset($mergePlugin)) {
            $this->io->write(" - Integrating with <info>wikimedia/composer-merge-plugin</info>.");
            // @todo This might not work properly with devMode.
            $event = new Event(ScriptEvents::PRE_INSTALL_CMD, $this->composer, $this->io);
            $mergePlugin->onInstallUpdateOrDump($event);
        }

        return $root->getExtra();
    }
}
