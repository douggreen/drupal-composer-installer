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

class DrupalInstallerPlugin implements PluginInterface, EventSubscriberInterface {

    /**
     * Optionally listen to post-patch-apply events.
     */
    const POST_PATCH_APPLY = 'post-patch-apply';

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var DrupalInstaller $installer
     */
    protected $installer;

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var ProcessExecutor $executor
     */
    protected $executor;

    /**
     * @var array $git
     */
    protected $git;

    /**
     * @var array $patches
     */
    protected $patches;

    public function activate(Composer $composer, IOInterface $io) {
        $this->io = $io;

        $this->installer = new DrupalInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);

        $extra = $composer->getPackage()->getExtra();
        $extra += array(
            'drupal-custom' => array(),
            'drupal-root' => 'core',
            'patches' => array(),
        );

        $this->drupalRoot = $extra['drupal-root'];
        $this->patches = $extra['patches'];

        $this->drupalCustom = $extra['drupal-custom'];
        $sitesDir = $this->drupalRoot . '/sites';
        if (!in_array($sitesDir, $this->drupalCustom)) {
            $this->drupalCustom[] = $sitesDir;
        }

        $this->tmp = array();
        $this->info = array();

        $this->composer = $composer;
        $this->executor = new ProcessExecutor($io);

        $this->git = isset($extra['git']) ? $extra['git'] : array();
        $this->git += array(
            'commit' => 0,
            'commit-prefix' => '',
            'path' => '.git-drupal',
            'base-branch' => '',
            'branch-prefix' => 'composer-',
            'auto-push' => 0,
            'remote' => 'origin',
        );

        $commitPrefix = getenv("COMPOSER_GIT_COMMIT_PREFIX");
        if ($commitPrefix) {
            $this->git['commit-prefix'] = $commitPrefix;
        }

        $this->io->write("  - activate DrupalInstallerPlugin");
    }

    public static function getSubscribedEvents() {
        $before = 'before';
        $after = array(
          array('after', 100),
          array('afterAllPatches', -100),
        );
        return array(
            PackageEvents::PRE_PACKAGE_INSTALL => $before,
            PackageEvents::PRE_PACKAGE_UPDATE => $before,
            PackageEvents::POST_PACKAGE_INSTALL => $after,
            PackageEvents::POST_PACKAGE_UPDATE => $after,
            self::POST_PATCH_APPLY => 'afterPatch',
        );
    }

    function before(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal === 'composer' || ($packageDrupal !== 'drupal' && $vendor !== 'drupal')) {
            return;
        }

        if ($this->io->isVeryVerbose()) {
            $this->io->write("DrupalInstaller $packageName $packageType [before]");
        }

        $this->beforeDrupalGitRestore($event, $package);

        if ($packageName === 'drupal/drupal') {
            $this->beforeDrupalSaveCustom($event);
        }
        else {
            $this->beforeDrupalRewriteInfo($event);
        }
    }

    protected function beforeDrupalSaveCustom(PackageEvent $event) {
        $this->io->write("  - Saving custom paths");

        // Change permissions for a better outcome when deleting existing sites,
        // since Drupal changes the permissions on these directories.
        $sitesDir = $this->drupalRoot . '/sites';
        if (is_dir($sitesDir)) {
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
        }

        $file = new FileSystem();

        foreach ($this->drupalCustom as $path) {
            if (!file_exists($path)) {
                continue;
            }

            if (!isset($this->tmpdir)) {
                $this->tmpdir = uniqid('/tmp/dci') . '.bak';
                if ($this->io->isVerbose()) {
                    $this->io->write("    - Ensuring <info>$this->tmpdir</info>");
                }
                $file->ensureDirectoryExists($this->tmpdir);
            }

            $basename = basename($path);
            $tmpfile = $this->tmpdir . '/' . $basename;
            if (file_exists($tmpfile)) {
                $tmpfile = $this->tmpdir . '/' . md5($path);
                if ($this->io->isVerbose()) {
                    $this->io->write("    - Ensuring <info>$tmpfile</info>");
                }
                $file->ensureDirectoryExists($tmpfile);
                $tmpfile .= '/' . $basename;
            }

            $this->io->write("    - Saving <info>$path</info> to <info>$tmpfile</info>");
            $file->rename($path, $tmpfile);
            $this->tmp[$path] = $tmpfile;
        }
    }

    protected function beforeDrupalRewriteInfo(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packagePath = $this->installer->getPackageBasePath($package);
        $this->readDirVersionInfo($event, $packagePath);
    }

    protected function readDirVersionInfo(PackageEvent $event, $dirPath) {
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
                $this->readDirVersionInfo($event, $filePath);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->info[$filePath] = $this->getFileDrupalInfo($event, $filePath);
            }
        }
    }

    function getFileDrupalInfo(PackageEvent $event, $filePath) {
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

    function getFileVersionInfo(PackageEvent $event, $filePath) {
        $contents = @file_get_contents($filePath);
        $regex = '/\s+version\s*=\s*"?([^"\s]*)"?/';
        if ($contents && preg_match_all($regex, $contents, $matches)) {
            return end($matches[1]);
        }
        return NULL;
    }

    function after(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal === 'composer' || ($packageDrupal !== 'drupal' && $vendor !== 'drupal')) {
            return;
        }

        if ($this->io->isVeryVerbose()) {
            $this->io->write("DrupalInstaller $packageName $packageType [after]");
        }

        if ($packageName === 'drupal/drupal') {
            $this->afterDrupalRestoreCustom($event);
        }
        else {
            $this->afterDrupalRewriteInfo($event, $package);
        }

        $this->afterDrupalGitBackup($event, $package);
        $this->afterDrupalGitCommit($event, $package);

        if (!isset($this->patches[$packageName])) {
            $this->afterAllPatchesGitBranchCleanup($package);
        }
    }

    protected function afterDrupalGitCommit(PackageEvent $event, $package) {
        if (!$this->git['commit']) {
            return;
        }

        $packageName = $package->getName();
        $packagePath = $this->installer->getPackageBasePath($package);

        if ($this->isGitDiff()) {
            $this->io->write('  - Committing <info>' . $packageName . '</info> with version <info>' . $package->getVersion(). '</info> to GIT.');
            $this->executeCommand('cd %s && git add --all . && git commit -m "' . $this->git['commit-prefix'] . 'Update package ' . $packageName . ' to version ' . $package->getVersion() . '"', $packagePath);
            $this->afterCommit($package);
        }
    }

    protected function afterCommit($package) {
        if (!$this->git['auto-push']) {
            return;
        }

        $branchName = $this->getBranchName($package);
        $this->io->write('  - Pushing <info>' . $branchName . '</info> to <info>' . $this->git['remote'] . '</info> to GIT.');
        $this->executeCommand('git push %s %s', $this->git['remote'], $branchName);
    }

    protected function afterDrupalRestoreCustom(PackageEvent $event) {
        if (!isset($this->tmpdir)) {
            return;
        }

        $this->io->write("  - Restoring custom paths");

        $file = new FileSystem();

        foreach ($this->tmp as $path => $tmpfile) {
            $this->io->write("    - Restoring <info>$path</info> from <info>$tmpfile</info>");
            $file->removeDirectory($path);
            $file->rename($tmpfile, $path);
        }

        $file->removeDirectory($this->tmpdir);
    }

    protected function afterDrupalRewriteInfo(PackageEvent $event, PackageInterface $package) {
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
        $this->rewriteDirInfo($event, $packagePath, $info);
    }

    protected function rewriteDirInfo(PackageEvent $event, $dirPath, $info) {
        $scanFiles = scandir($dirPath);
        foreach ($scanFiles as $partialPath) {
            if ($partialPath === '.' || $partialPath === '..') {
                continue;
            }

            $filePath = "$dirPath/$partialPath";

            if (is_dir($filePath)) {
                $this->rewriteDirInfo($event, $filePath, $info);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->rewriteFileInfo($event, $filePath, $info);
            }
        }
    }

    protected function rewriteFileInfo(PackageEvent $event, $filePath, $info) {
        $version = $this->getFileVersionInfo($event, $filePath);
        if (!$version) {
            if (strpos($info['version'], 'dev') === FALSE && isset($this->info[$filePath]['version']) && $this->info[$filePath]['version'] === $info['version']) {
                $info = $this->info[$filePath] + $info;
            }

            $this->io->write("  - Rewriting <info>$filePath</info> with version <info>$info[version]</info>");

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
        }
    }

    protected function beforeDrupalGitRestore(PackageEvent $event, PackageInterface $package) {
        if (!$this->git['path']) {
            return;
        }

        $packagePath = $this->installer->getPackageBasePath($package);
        $gitPath = "$packagePath/.git";
        $backupPath = $packagePath . '/' . $this->git['path'];

        if ($this->git['base-branch']) {
            $newBranchName = $this->getBranchName($package);

            $this->io->write("  - Creating branch <info>$newBranchName</info> in GIT.");
            $this->executeCommand('git branch %s %s --force && git checkout %s', $newBranchName, $this->git['base-branch'], $newBranchName, $newBranchName);
        }

        if (!file_exists($gitPath) && file_exists($backupPath)) {
            $file = new FileSystem();
            $file->rename($backupPath, $gitPath);

            $this->io->write("Restored <info>$gitPath</info> from <info>$backupPath</info>.");
        }
    }

    protected function afterDrupalGitBackup(PackageEvent $event, PackageInterface $package) {
        if (!$this->git['path']) {
            return;
        }

        $packagePath = $this->installer->getPackageBasePath($package);
        $gitPath = "$packagePath/.git";
        $backupPath = $packagePath . '/' . $this->git['path'];

        if (file_exists($gitPath)) {
            $this->io->write("  - Moving <info>$gitPath</info> to <info>$backupPath</info>.");

            $file = new FileSystem();
            $file->removeDirectory($backupPath);
            $file->rename($gitPath, $backupPath);
        }
    }

    public function afterPatch(PatchEvent $event) {
        $package = $event->getPackage();
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal !== 'drupal') {
            return;
        }

        if ($this->io->isVeryVerbose()) {
            $packageName = $package->getName();
            $this->io->write("DrupalInstaller $packageName $packageType [afterPatch]");
        }

        $this->afterPatchGitCommit($event, $package);
    }

    protected function afterPatchGitCommit(PatchEvent $event, PackageInterface $package) {
        if (!$this->git['commit']) {
            return;
        }

        $packagePath = $this->installer->getPackageBasePath($package);
        $packageName = $package->getName();

        $url = $event->getUrl();
        $description = $event->getDescription();

        if ($this->isGitDiff()) {
            $this->io->write('  - Committing patch <info>' . $url . '</info> <comment>' . $description . '</comment> for package <info>' . $packageName . '</info> to GIT.');
            $this->executeCommand('cd %s && git add --all . && git commit -m "' . $this->git['commit-prefix'] . 'Applied patch ' . $url . ' (' . $description . ') for ' . $packageName . '."', $packagePath);
            $this->afterCommit($package);
        }
    }

    protected function afterAllPatchesGitBranchCleanup($package) {
        $branchName = $this->getBranchName($package);

        if (!$this->git['base-branch'] || $this->isGitDiff($branchName)) {
            return;
        }

        $this->io->write('  - Removing local branch <info>' . $branchName. '</info> from GIT because it is unchanged.');
        $this->executeCommand('git checkout %s && git branch -D %s', $this->git['base-branch'], $branchName);

        if (!$this->git['auto-push']) {
            return;
        }

        $this->io->write('  - Removing upstream branch <info>' . $branchName. '</info> from GIT remote <info>' . $this->git['remote'] . '</info> because it is unchanged.');
        $this->executeCommand("git push %s :%s", $this->git['base-branch'], $branchName);
    }

    function afterAllPatches(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal === 'drupal') {
            $packageName = $package->getName();
            if (isset($this->patches[$packageName])) {
                $this->afterAllPatchesGitBranchCleanup($package);
            }
        }
    }

    /**
     * Return TRUE if the current git branch has any uncommitted changes.
     *
     * @return bool
     */
    protected function isGitDiff($branchName = '') {
        $output = '';
        $command = "git diff";
        if ($branchName) {
            $command .= ' ' . $this->git['base-branch'] . ' ' . $branchName;
        }
        if ($this->executor->execute($command, $output) == 0) {
          $output = trim($output);
          if ($this->io->isVeryVerbose() && $output) {
              $this->io->write("<comment>" . substr($output, 0, 10) . "...</comment>");
          }
          return $output ? TRUE : FALSE;
        }
        return FALSE;
    }

    protected function getPackage(PackageEvent $event) {
        $operation = $event->getOperation();
        foreach (array('getPackage', 'getTargetPackage') as $method) {
            if (method_exists($operation, $method)) {
                return $operation->$method();
            }
        }
        return NULL;
    }

    protected function getPackageName(PackageEvent $event) {
        return $this->getPackage($event)->getName();
    }

    protected function getBranchName($package) {
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        return str_replace('_', '-', $this->git['branch-prefix'] . $project);
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
        return $this->executor->execute($command, $output) == 0;
    }
}
