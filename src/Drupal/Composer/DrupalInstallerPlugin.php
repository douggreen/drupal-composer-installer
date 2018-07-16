<?php

namespace Drupal\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Script\Event;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\FileSystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;
use SimpleXMLElement;

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

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io) {
        $this->composer = $composer;
        $this->io = $io;
        $this->executor = new ProcessExecutor($io);

        $this->installer = new DrupalInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);
    }

    /**
     * Initializes all plugin options.
     */
    public function init() {
        $extra = $this->composer->getPackage()->getExtra();
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

        $this->git = isset($extra['git']) ? $extra['git'] : array();
        $this->git += array(
            'commit' => 0,
            'commit-prefix' => '',
            'path' => '.git-drupal',
            'base-branch' => '',
            'branch-prefix' => 'composer-',
            'auto-push' => 0,
            'auto-remove' => 1,
            'remote' => 'origin',
            'security' => 0,
        );

        // Read user environment overrides.
        $remote = getenv("COMPOSER_GIT_REMOTE");
        if ($remote !== FALSE) {
            $this->git['remote'] = $remote;
        }
        $commitPrefix = getenv("COMPOSER_GIT_COMMIT_PREFIX");
        if ($commitPrefix !== FALSE) {
            $this->git['commit-prefix'] = $commitPrefix;
        }
        $security = getenv("COMPOSER_GIT_SECURITY");
        if ($security !== FALSE) {
            $this->git['security'] = !empty($security);
        }
        $autoRemove = getenv("COMPOSER_GIT_AUTO_REMOVE");
        if ($autoRemove !== FALSE) {
            $this->git['auto-remove'] = !empty($autoRemove);
        }

        $this->io->write("  - initialize DrupalInstallerPlugin");

        if ($this->io->isVeryVerbose()) {
          $this->io->write("    - <info>drupalRoot</info>=<info>$this->drupalRoot</info>");
          if ($this->git['commit']) {
            foreach ($this->git as $option => $value) {
              $this->io->write("    - <info>git.$option</info>=<info>$value</info>");
            }
          }
          else {
            $this->io->write("    - <info>git.commit</info>=<info>0</info>");
          }
          foreach ($this->drupalCustom as $customPath) {
            $this->io->write("    - <info>drupalCustom[]</info>=<info>$customPath</info>");
          }
        }
    }

    public static function getSubscribedEvents() {
        $before = 'before';
        $after = array(
          array('after', 100),
          array('afterAllPatches', -100),
        );
        return array(
	    // Ensure we run after wikimedia-merge-plugin.
            PluginEvents::INIT => array('init', -100),
            PackageEvents::PRE_PACKAGE_INSTALL => $before,
            PackageEvents::PRE_PACKAGE_UPDATE => $before,
            PackageEvents::POST_PACKAGE_INSTALL => $after,
            PackageEvents::POST_PACKAGE_UPDATE => $after,
            self::POST_PATCH_APPLY => 'afterPatch',
        );
    }

    public function before(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($this->io->isVeryVerbose()) {
            $this->io->write("DrupalInstallerPlugin::before::name=$packageName, type=$packageType");
        }

        // Do not overwrite users changes.
        if ($this->isGitDiff()) {
          throw new \Exception(sprintf('There are uncommitted changes which will be removed. Please commit all uncommitted changes first.'));
        }

        if ($packageType !== 'library') {
            if ($packageDrupal === 'composer' || $packageType === 'metapackage' || ($packageDrupal !== 'drupal' && $vendor !== 'drupal')) {
                return;
            }

            $this->beforeDrupalReadInfo($event);
            if ($packageName === 'drupal/drupal') {
                $this->beforeDrupalSaveCustom($event);
            }
        }

        $this->beforeDrupalGitRestore($event, $package);
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

    protected function beforeDrupalReadInfo(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageName = $package->getName();
        $packagePath = $this->installer->getInstallPath($package);
        $this->readDirVersionInfo($packageName, $packagePath);
    }

    protected function readDirVersionInfo($packageName, $dirPath) {
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
                $this->readDirVersionInfo($packageName, $filePath);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->info[$packageName][$filePath] = $this->getFileDrupalInfo($filePath);
            }
        }
    }

    protected function getFileDrupalInfo($filePath) {
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

    protected function getFileVersionInfo($filePath) {
        $contents = @file_get_contents($filePath);
        $regex = '/\s+version\s*=\s*"?([^"\s]*)"?/';
        if ($contents && preg_match_all($regex, $contents, $matches)) {
            $version = end($matches[1]);
            return $this->normalizeVersion($version);
        }
        return NULL;
    }

    public function after(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($this->io->isVeryVerbose()) {
            $this->io->write("DrupalInstallerPlugin::after::name=$packageName, type=$packageType");
        }

        if ($packageType !== 'library') {
            if ($packageDrupal === 'composer' || $packageType === 'metapackage' || ($packageDrupal !== 'drupal' && $vendor !== 'drupal')) {
                return;
            }

            if ($packageName === 'drupal/drupal') {
                $this->afterDrupalRestoreCustom($event);
            }
            else {
                $this->afterDrupalRewriteInfo($event, $package);
            }
        }

        $this->afterDrupalGitBackup($event, $package);
        $this->afterDrupalGitCommit($event, $package);

        if (!isset($this->patches[$packageName])) {
            $this->afterAllPatchesGitBranchCleanup($package);
        }
    }

    protected function afterDrupalGitCommit(PackageEvent $event, PackageInterface $package) {
        if (!$this->git['commit']) {
            return;
        }

        $packageName = $package->getName();
        $packagePath = $this->installer->getInstallPath($package);
        $version = $this->getPackageVersion($package);

        $this->io->write("  - Committing <info>$packageName</info> with version <info>$version</info> to GIT.");
        $this->executeCommand('cd %s && git add --all . && { git diff --cached --quiet || git commit -m "' . $this->git['commit-prefix'] . 'Update package ' . $packageName . ' to version ' . $version . '"; }', $packagePath);
    }

    protected function afterCommit(PackageInterface $package) {
        // Just in case the commit failed, cleanup the branch.
        $this->executeCommand('git reset --hard');

        if (!$this->git['auto-push']) {
            return;
        }

        $branchName = $this->getBranchName($package);
        $this->io->write('  - Pushing <info>' . $branchName . '</info> to <info>' . $this->git['remote'] . '</info> to GIT.');
        $this->executeCommand('git push %s %s --force', $this->git['remote'], $branchName);
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
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);

        $packagePath = $this->installer->getInstallPath($package);

        $info = array(
            'project' => $project,
            'version' => $this->getPackageVersion($package),
            'date' => date('Y-m-d'),
            'datestamp' => time(),
        );
        $this->rewriteDirInfo($packageName, $packagePath, $info);
    }

    protected function rewriteDirInfo($packageName, $dirPath, $info) {
        $scanFiles = scandir($dirPath);
        foreach ($scanFiles as $partialPath) {
            if ($partialPath === '.' || $partialPath === '..') {
                continue;
            }

            $filePath = "$dirPath/$partialPath";

            if (is_dir($filePath)) {
                $this->rewriteDirInfo($packageName, $filePath, $info);
            }
            elseif (substr($partialPath, -5) === '.info') {
                $this->rewriteFileInfo($packageName, $filePath, $info);
            }
        }
    }

    protected function rewriteFileInfo($packageName, $filePath, $info) {
        $version = $this->getFileVersionInfo($filePath);
        $old_info = $this->getFileDrupalInfo($filePath);
        if (!$version || (isset($info['project']) && !isset($old_info['project'])) || (isset($info['datestamp']) && !isset($old_info['datestamp']))) {
            if (strpos($info['version'], 'dev') === FALSE && isset($this->info[$packageName][$filePath]['version']) && $this->info[$packageName][$filePath]['version'] === $info['version']) {
                $info = $this->info[$packageName][$filePath] + $info;
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

        $packagePath = $this->installer->getInstallPath($package);
        $gitPath = "$packagePath/.git";
        $backupPath = $packagePath . '/' . $this->git['path'];

        if ($this->git['base-branch']) {
            $this->verifyGitBranchExists($this->git['base-branch']);

            $newBranchName = $this->getBranchName($package);

            $this->io->write("  - Creating branch <info>$newBranchName</info> in GIT.");
            $this->executeCommand('git reset --hard && git branch %s %s --force && git checkout %s', $newBranchName, $this->git['base-branch'], $newBranchName, $newBranchName);
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

        $packagePath = $this->installer->getInstallPath($package);
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

        if ($this->io->isVeryVerbose()) {
            $packageName = $package->getName();
            $this->io->write("DrupalInstallerPlugin::afterPatch::name=$packageName, type=$packageType");
        }

        if ($packageDrupal !== 'drupal') {
            return;
        }

        $this->afterPatchGitCommit($event, $package);
    }

    protected function afterPatchGitCommit(PatchEvent $event, PackageInterface $package) {
        if (!$this->git['commit']) {
            return;
        }

        $packagePath = $this->installer->getInstallPath($package);
        $packageName = $package->getName();

        $url = $event->getUrl();
        $description = $event->getDescription();

        $this->io->write('  - Committing patch <info>' . $url . '</info> <comment>' . $description . '</comment> for package <info>' . $packageName . '</info> to GIT.');
        $this->executeCommand('cd %s && git add --all . && { git diff --cached --quiet || git commit -m "' . $this->git['commit-prefix'] . 'Applied patch ' . $url . ' (' . $description . ') for ' . $packageName . '."; }', $packagePath);
        // The branch will be pushed after all patches have been committed.
    }

    protected function afterAllPatchesGitBranchCleanup(PackageInterface $package) {
        $branchName = $this->getBranchName($package);

        if ($this->io->isVeryVerbose()) {
            $this->io->write("DrupalInstallerPlugin::afterAllPatchesGitBranchCleanup::branch=$branchName");
        }

        if (!$this->git['base-branch']) {
            return;
        }

        $this->verifyGitBranchExists($this->git['base-branch']);

        $isGitDiff = $this->isGitDiff($branchName);
        if ($isGitDiff) {
          $this->afterCommit($package);
        }

        if ($isGitDiff && (!$this->git['security'] || substr($branchName, -3) === '-SA')) {
            if ($this->io->isVeryVerbose()) {
                $this->io->write("  - Keeping branch <info>$branchName</info>, git.security=" . $this->git['security'] . ", sa=" .  substr($branchName, -3) . '.');
            }
            return;
        }

        if (!$this->git['auto-remove']) {
          return;
        }

        $this->io->write("  - Removing local branch <info>$branchName</info> from GIT.");
        $this->executeCommand('git checkout %s && git branch -D %s', $this->git['base-branch'], $branchName);

        if (!$this->git['auto-push']) {
            return;
        }

        $this->io->write("  - Removing upstream branch <info>$branchName</info> from GIT remote <info>" . $this->git['remote'] . "</info>.");
        $this->executeCommand("git push %s :%s", $this->git['remote'], $branchName);
    }

    public function afterAllPatches(PackageEvent $event) {
        $package = $this->getPackage($event);
        $packageType = $package->getType();
        list($packageDrupal) = explode('-', $packageType);

        if ($packageDrupal === 'drupal') {
            $packageName = $package->getName();
            if (isset($this->patches[$packageName])) {
                $this->afterAllPatchesGitBranchCleanup($package);
            }
        }
        if ($this->git['base-branch']) {
          $this->executeCommand("git checkout %s", $this->git['base-branch']);
        }
    }

    protected function verifyGitBranchExists($branch_name) {
        // verify the base branch exists.
        if (!$this->executeCommand('git rev-parse --verify %s', $branch_name)) {
            throw new \Exception(sprintf('Specified base-branch "%s" does not exist', $branch_name));
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

    protected function getPackageVersion(PackageInterface $package) {
        $version = $package->getPrettyVersion();

        // Detect endpoint.
        $platform = FALSE;
        $repo = $package->getRepository();
        if (method_exists($repo, 'getRepoConfig')) {
            $repo_config = $repo->getRepoConfig();
            if (isset($repo_config['url'])) {
                // The new packages.drupal.org separates the platform from the version entirely.
                $repourl = parse_url($repo_config['url']);
                if ($repourl['host'] == 'packages.drupal.org') {
                    $platform = substr($repourl['path'], 1);
                }
            }
        }


        if (substr($version, 0, 4) === 'dev-') {
            if ($platform) {
                return $platform . '.x-' . substr($version, 4);
            }
            return substr($version, 4);
        }

        // Convert composer versions back to Drupal versions.
        $packageName = $package->getName();
        list($vendor, $project) = explode('/', $packageName);
        if ($vendor === 'drupal') {
            if (preg_match('/(?P<major>\d+)\.(?P<minor>\d+)(?:\.(?P<patch>\d+))?(?P<extra>-[\w\d]+)?/', $version, $matches)) {
                if ($project === 'drupal') {
                    // Drupal core versions have two numbers, i.e. 7.38.
                    $version = $matches['major'] . '.' . $matches['minor'];
                    // Drupal core's last last number should always be 0.
                    if (!empty($matches['patch'])) {
                        $version .= '.' . $matches['patch'];
                    }
                }
                else {
                    if ($platform) {
                        $version = $platform . '.x-' . $matches['major'] . '.' . $matches['minor'];
                        if (!empty($matches['patch'])) {
                            $version .= '.' . $matches['patch'];
                        }
                    }
                    else {
                        // Legacy behavior.
                        // Drupal contrib versions have three numbers, i.e. 7.x-1.7.
                        $version = $matches['major'] . '.x-' . $matches['minor'] . '.' . $matches['patch'];
                    }
                }
                if (!empty($matches['extra'])) {
                    $version .= $matches['extra'];
                }
            }
        }

        else {
             // Convert v1.2.3 to just 1.2.3 as special case for how I tag local
             // projects. @todo: generlize this?
             if (preg_match('/v[\d+.]*/', $version)) {
                 $version = substr($version, 1);
             }

             // Replace / in version with a dash.
             $version = str_replace('/', '-', $version);
        }

        return $version;
    }

    protected function getPackageFileVersion(PackageInterface $package) {
        $packageName = $package->getName();
        if (isset($this->info[$packageName])) {
            $packageInfo = reset($this->info[$packageName]);
            if (isset($packageInfo['version'])) {
                return $this->normalizeVersion($packageInfo['version']);
            }
        }
        return NULL;
    }

    protected function getBranchName(PackageInterface $package) {
        $packageName = $package->getName();

        static $cached;
        if (!isset($cached[$packageName])) {
            list($vendor, $project) = explode('/', $packageName);
            $version = $this->getPackageVersion($package);
            $branchVersion = preg_match('/\d+.x-(.*)/', $version, $match) ? $match[1] : $version;

            $branchName = str_replace('_', '-', $this->git['branch-prefix'] . $project) . '-' . $version;
            if ($vendor === 'drupal' && $this->isSecurityAdvisory($package, $project, $version)) {
                $branchName .= '-SA';
            }

            $cached[$packageName] = $branchName;
        }

        return isset($cached[$packageName]) ? $cached[$packageName] : 'unknown';
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

    protected function isSecurityAdvisory(PackageInterface $package, $project, $version) {
        // Development release can never be a Security Advisory.
        if (strpos($version, 'dev') !== FALSE) {
            return;
        }

        $history = $this->getUpdateReleaseHistory($project, $version[0]);
        if (isset($history['releases'])) {
            $version = $this->normalizeVersion($version);
            $oldVersion = $this->getPackageFileVersion($package);
            $oldVersion = $this->normalizeVersion($oldVersion);

            foreach ($history['releases'] as $releaseVersion => $releaseInfo) {
                $releaseVersion = $this->normalizeVersion($releaseVersion);
                if (isset($releaseInfo['terms']['Release type']) && in_array('Security update', $releaseInfo['terms']['Release type'])) {
                    if (version_compare($oldVersion, $releaseVersion) < 0 && version_compare($version, $releaseVersion) >= 0) {
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }

    protected function normalizeVersion($version) {
        $version = strtolower($version);
        $stabilities = array('dev', 'unstable', 'alpha', 'beta', 'rc');
        $stabilities_regex = implode('|', $stabilities);
        if (empty($version) || !preg_match_all('/((' . $stabilities_regex . ')?[0-9]+)/', $version, $version_matches)) {
            $versions = array(0, 0, 0, 0);
        }
        else {
            $versions = $version_matches[0];
            $stability_versions = preg_grep('/' . $stabilities_regex . '/', $versions);
            if ($stability_versions) {
                $versions = array_diff($versions, $stability_versions);
            }

            if (preg_match('/(' . $stabilities_regex . ')([0-9]+)?/', $version, $stability_matches)) {
                $versions[] = array_search($stability_matches[1], $stabilities);
                if (!empty($stability_matches[2])) {
                    $versions[] = $stability_matches[2];
                }
            }
            // Look for a version that ends in .x, such as 7.x-1.x,
            // which is a dev version.
            elseif (substr($version, -2) == '.x') {
                $versions[] = 0;
            }
        }

        $new_version = implode('.', $versions);
        return $new_version;
    }

    protected function getUpdateReleaseHistory($project, $major) {
        $url = "http://updates.drupal.org/release-history/$project/$major.x";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Drupal composer installer');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $raw_xml = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status === 200 ? $this->update_parse_xml($raw_xml) : array();
    }

    /**
     * Parses the XML of the Drupal release history info files.
     *
     * Copied from Drupal core.
     *
     * @param $raw_xml
     *   A raw XML string of available release data for a given project.
     *
     * @return
     *   Array of parsed data about releases for a given project, or NULL if there
     *   was an error parsing the string.
     */
    protected function update_parse_xml($raw_xml) {
        $xml = simplexml_load_string($raw_xml);
        // If there is no valid project data, the XML is invalid, so return failure.
        if ($xml === FALSE || !isset($xml->short_name)) {
            return;
        }
        $short_name = (string) $xml->short_name;
        $data = array();
        foreach ($xml as $k => $v) {
            $data[$k] = (string) $v;
        }
        $data['releases'] = array();
        if (isset($xml->releases)) {
            foreach ($xml->releases->children() as $release) {
                $version = (string) $release->version;
                $data['releases'][$version] = array();
                foreach ($release->children() as $k => $v) {
                    $data['releases'][$version][$k] = (string) $v;
                }
                $data['releases'][$version]['terms'] = array();
                if ($release->terms) {
                    foreach ($release->terms->children() as $term) {
                        if (!isset($data['releases'][$version]['terms'][(string) $term->name])) {
                            $data['releases'][$version]['terms'][(string) $term->name] = array();
                        }
                        $data['releases'][$version]['terms'][(string) $term->name][] = (string) $term->value;
                    }
                }
            }
        }
        return $data;
    }
}
