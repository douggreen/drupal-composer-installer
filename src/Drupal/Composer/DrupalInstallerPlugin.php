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
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;

// Optionally integrate with composer-patches.
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;

class DrupalInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Optionally listen to post-patch-apply events.
     */
    const POST_PATCH_APPLY = 'post-patch-apply';

    /**
     * @var Composer $composer
     */
    protected $composer;
    /**
     * @var IOInterface $io
     */
    protected $io;
    /**
     * @var ProcessExecutor $executor
     */
    protected $executor;
    /**
     * @var bool $useGit
     */
    protected $useGit;
    /**
     * @var string $gitCommitMessagePrefix
     */
    protected $gitCommitMessagePrefix;

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

        $this->drupalCustom = $extra['drupal-custom'];
        foreach (array('modules', 'themes') as $subdir) {
            $path = $this->drupalRoot . '/sites/all/' . $subdir . '/custom';
            if ($this->isUniqueDir($io, $path, $this->drupalCustom)) {
                $this->drupalCustom[] = $path;
            }
        }

        $this->noGitDir = !empty($extra['no-git-dir']);

        $this->tmp = array();
        $this->info = array();

        $this->composer = $composer;
        $this->io = $io;
        $this->executor = new ProcessExecutor($this->io);

        $this->useGit = getenv('COMPOSER_PATCHES_USE_GIT') == '1';
        $this->gitCommitMessagePrefix = getenv('COMPOSER_PATCHES_GIT_COMMIT_MESSAGE_PREFIX');
    }

    protected function isUniqueDir(IOInterface $io, $path, $dirs) {
        $io->write("<info>path=$path, dirs=" . print_r($dirs, 1) . "</info>");
        for ($parts = explode('/', $path); $parts; array_pop($parts)) {
            $path = implode('/', $parts);
            $io->write("<info>Checking for $path</info>");
            if (in_array($path, $dirs)) {
                $io->write("<info>Checking for $path -- FOUND</info>");
                return FALSE;
            }
        }
        $io->write("<info>NOT FOUND</info>");
        return TRUE;
    }

    public static function getSubscribedEvents() {
        return array(
            PackageEvents::PRE_PACKAGE_INSTALL => 'before',
            PackageEvents::PRE_PACKAGE_UPDATE => 'before',
            // Use a higher priority than composer-patches.
            PackageEvents::POST_PACKAGE_INSTALL => array('after', 100),
            PackageEvents::POST_PACKAGE_UPDATE => array('after', 100),
            self::POST_PATCH_APPLY => 'afterPatch',
        );
    }

    function before(PackageEvent $event) {
        $io = $event->getIO();

        $package = $this->getPackage($event, $io);
        $packageName = $package->getName();
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageName === 'drupal/drupal') {
            $this->beforeDrupalSaveCustom($event, $io);
            $this->beforeDrupalRewriteInfo($event, $io);
        }
        elseif ($packageDrupal === 'drupal') {
            $this->beforeDrupalRewriteInfo($event, $io);
        }
        if ($packageDrupal === 'drupal' && $this->noGitDir) {
            $this->beforeDrupalRemoveGitDir($event, $io, $package);
        }
    }

    protected function beforeDrupalSaveCustom(PackageEvent $event, IOInterface $io) {
        // Change permissions for a better outcome when deleting existing sites,
        // since Drupal changes the permissions on these directories.
        $sitesDir = $this->drupalRoot . '/sites';
        if (!is_dir($sitesDir)) {
            $io->write("<error>Missing $sitesDir->tmpdir</error>");
            return;
        }
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

    protected function beforeDrupalRewriteInfo(PackageEvent $event, IOInterface $io) {
        $package = $this->getPackage($event, $io);
        $packagePath = $this->installer->getPackageBasePath($package);
        $this->readDirVersionInfo($event, $io, $packagePath);
    }

    protected function readDirVersionInfo(PackageEvent $event, IOInterface $io, $dirPath) {
        if (!is_dir($dirPath)) {
            return;
        }
        $scanFiles = @scandir($dirPath);
        foreach ($scanFiles as $partialPath) {
            if ($partialPath === '.' || $partialPath === '..') {
                continue;
            }

            $filePath = "$dirPath/$partialPath";

            if (is_dir($filePath)) {
                $this->readDirVersionInfo($event, $io, $filePath);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->info[$filePath] = $this->getFileDrupalInfo($event, $io, $filePath);
            }
        }
    }

    function getFileDrupalInfo(PackageEvent $event, IOInterface $io, $filePath) {
        $info = array();
        $contents = @file($filePath);
        if ($contents) {
            foreach ($contents as $line) {
                if (preg_match('/^\s*(\w+)\s*=\s*"?([^"\n]*)"?/', $line, $matches)) {
                    $key = $matches[1];
                    $value = $matches[2];
                    $info[$key] = $value;
                }
                elseif (preg_match('/^;.*on (\d\d\d\d-\d\d-\d\d)/', $line, $matches)) {
                    $info['date'] = $matches[1];
                }
            }
        }
        return $info ? $info : NULL;
    }

    function getFileVersionInfo(PackageEvent $event, IOInterface $io, $filePath) {
        $contents = @file_get_contents($filePath);
        $regex = '/\s+version\s*=\s*"?([^"\s]*)"?/';
        if ($contents && preg_match_all($regex, $contents, $matches)) {
            return end($matches[1]);
        }
        return NULL;
    }

    function after(PackageEvent $event) {
        $io = $event->getIO();

        $package = $this->getPackage($event, $io);
        $packageName = $package->getName();
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageName === 'drupal/drupal') {
            $this->afterDrupalRestoreCustom($event, $io);
        }
        elseif ($packageDrupal === 'drupal') {
            $this->afterDrupalRewriteInfo($event, $io, $package);
        }
        if ($packageDrupal === 'drupal' && $this->noGitDir) {
            $this->afterDrupalRemoveGitDir($event, $io, $package);
        }

        // This needs to come after the noGitDir for maximum effectiveness.
        if ($packageDrupal === 'drupal' && $this->useGit) {
            $packagePath = $this->installer->getPackageBasePath($package);
            // Commit the package.
            $this->io->write('  - Committing <info>' . $packageName . '</info> with version <info>' . $package->getVersion(). '</info> to GIT.');
            $this->executeCommand('cd %s && git add --all . && git commit . -m "' . $this->gitCommitMessagePrefix . 'Update package %s to version %s"', $packagePath, $packageName, $package->getVersion());
        }
    }

    protected function afterDrupalRestoreCustom(PackageEvent $event, IOInterface $io) {
        if (!isset($this->tmpdir)) {
            return;
        }

        $file = new FileSystem();

        foreach ($this->tmp as $path => $tmpfile) {
            $io->write("<info>Restore $path from $tmpfile</info>");
            $file->removeDirectory($path);
            $file->rename($tmpfile, $path);
        }

        $file->removeDirectory($this->tmpdir);
    }

    protected function afterDrupalRewriteInfo(PackageEvent $event, IOInterface $io, PackageInterface $package) {
        $packageVersion = $package->getVersion();
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);

        $packagePath = $this->installer->getPackageBasePath($package);

        $info = array(
            'project' => $project,
            'version' => $packageVersion,
            'date' => date('Y-m-d'),
            'datestamp' => time(),
        );
        $this->rewriteDirInfo($event, $io, $packagePath, $info);
    }

    protected function rewriteDirInfo(PackageEvent $event, IOInterface $io, $dirPath, $info) {
        $scanFiles = scandir($dirPath);
        foreach ($scanFiles as $partialPath) {
            if ($partialPath === '.' || $partialPath === '..') {
                continue;
            }

            $filePath = "$dirPath/$partialPath";

            if (is_dir($filePath)) {
                $this->rewriteDirInfo($event, $io, $filePath, $info);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->rewriteFileInfo($event, $io, $filePath, $info);
            }
        }
    }

    protected function rewriteFileInfo(PackageEvent $event, IOInterface $io, $filePath, $info) {
        $version = $this->getFileVersionInfo($event, $io, $filePath);
        if (!$version) {
            if (strpos($info['version'], 'dev') === FALSE && isset($this->info[$filePath]['version']) && $this->info[$filePath]['version'] === $info['version']) {
                $info = $this->info[$filePath] + $info;
            }

            $moreInfo = "\n"
                . "; Information added by drupal-composer-installer packaging script on $info[date]\n"
                . "version = \"$info[version]\"\n";
            if (isset($info['project'])) {
                $moreInfo .= "project = \"$info[project]\"\n";
            }
            if (isset($info['datestamp'])) {
                $moreInfo .= "datestamp = \"$info[datestamp]\"\n";
            }
            file_put_contents($filePath, $moreInfo, FILE_APPEND);

            $io->write("<info>Rewrite $filePath $info[version]</info>");
        }
    }

    protected function beforeDrupalRemoveGitDir(PackageEvent $event, IOInterface $io, PackageInterface $package) {
        $packagePath = $this->installer->getPackageBasePath($package);
        $gitPath = "$packagePath/.git";
        $backupPath = "$packagePath/.git-drupal";

        if (!file_exists($gitPath) && file_exists($backupPath)) {
            $file = new FileSystem();
            $file->rename($backupPath, $gitPath);

            $io->write("Restored <info>$gitPath</info> from <info>$backupPath</info>.");
        }
    }

    protected function afterDrupalRemoveGitDir(PackageEvent $event, IOInterface $io, PackageInterface $package) {
        $packagePath = $this->installer->getPackageBasePath($package);
        $gitPath = "$packagePath/.git";
        $backupPath = "$packagePath/.git-drupal";

        if (file_exists($gitPath)) {
            $file = new FileSystem();
            $file->removeDirectory($backupPath);
            $file->rename($gitPath, $backupPath);

            $io->write("Removed <info>$gitPath</info> and stored as <info>$backupPath</info>.");
        }
    }

    public function afterPatch(PatchEvent $event) {
        $package = $event->getPackage();
        $packageName = $package->getName();
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal === 'drupal' && $this->useGit) {
            $packagePath = $this->installer->getPackageBasePath($package);

            $url = $event->getUrl();
            $description = $event->getDescription();

            // Commit the package.
            $this->io->write('  - Committing patch <info>' . $url . '</info> (<comment>' . $description . '</comment>) for package <info>' . $packageName . '</info> to GIT.');
            $this->executeCommand('cd %s && git add --all . && git commit . -m "' . $this->gitCommitMessagePrefix . 'Applied patch %s (%s) for %s."', $packagePath, $url, $description, $packageName);
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
        return $this->getPackage($event, $io)->getName();
    }

  /**
   * Executes a shell command with escaping.
   *
   * @param string $cmd
   * @return bool
   */
  protected function executeCommand($cmd) {
    // Shell-escape all arguments except the command.
    $args = func_get_args();
    foreach ($args as $index => $arg) {
      if ($index !== 0) {
        $args[$index] = escapeshellarg($arg);
      }
    }
    // And replace the arguments.
    $command = call_user_func_array('sprintf', $args);
    $output = '';
    if ($this->io->isVerbose()) {
      $this->io->write('<comment>' . $command . '</comment>');
      $io = $this->io;
      $output = function ($type, $data) use ($io) {
        if ($type == Process::ERR) {
          $io->write('<error>' . $data . '</error>');
        }
        else {
          $io->write('<comment>' . $data . '</comment>');
        }
      };
    }
    return ($this->executor->execute($command, $output) == 0);
  }

}
