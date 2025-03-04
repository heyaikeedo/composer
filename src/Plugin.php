<?php

declare(strict_types=1);

namespace Aikeedo\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\DependencyResolver\Operation\OperationInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected IOInterface $io;
    protected Composer $composer;
    protected Installer $installer;
    protected string $webroot;

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);

        $this->webroot = $this->determineWebroot($composer);
        $io->write(sprintf('<info>Using webroot: %s</info>', $this->webroot), true, IOInterface::VERBOSE);
    }

    /**
     * Determine the webroot path from various sources
     */
    private function determineWebroot(Composer $composer): string
    {
        // Check environment variable
        $envWebroot = getenv('PUBLIC_DIR');
        if ($envWebroot !== false) {
            return $envWebroot;
        }

        // Default value
        return 'public';
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // No action needed
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // No action needed
    }

    /**
     * @return array<string, string|array<string>>
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstall',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPackageUninstall',
        ];
    }

    /**
     * Handle post package installation event
     */
    public function onPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAikeedoPackage($package)) {
            return;
        }

        $this->copyPackageFiles($package);
    }

    /**
     * Handle pre package uninstallation event
     */
    public function onPackageUninstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAikeedoPackage($package)) {
            return;
        }

        $this->removePackageFiles($package);
    }

    /**
     * Extract the package from an operation based on operation type
     */
    private function getPackageFromOperation(OperationInterface $operation): ?PackageInterface
    {
        $operationType = $operation->getOperationType();

        switch ($operationType) {
            case 'install':
                return $operation instanceof \Composer\DependencyResolver\Operation\InstallOperation
                    ? $operation->getPackage()
                    : null;

            case 'update':
                return $operation instanceof \Composer\DependencyResolver\Operation\UpdateOperation
                    ? $operation->getTargetPackage()
                    : null;

            case 'uninstall':
                return $operation instanceof \Composer\DependencyResolver\Operation\UninstallOperation
                    ? $operation->getPackage()
                    : null;

            default:
                return null;
        }
    }

    /**
     * Check if package is an Aikeedo package
     */
    private function isAikeedoPackage(PackageInterface $package): bool
    {
        $packageType = $package->getType();
        return in_array($packageType, ['aikeedo-plugin', 'aikeedo-theme']);
    }

    /**
     * Copy files from package to target directory
     */
    private function copyPackageFiles(PackageInterface $package): void
    {
        $extra = $package->getExtra();

        // Skip if no public files are defined in the package
        if (!isset($extra['public']) || !is_array($extra['public']) || empty($extra['public'])) {
            return;
        }

        $publicFiles = $extra['public'];
        $installPath = $this->installer->getInstallPath($package);
        $packageName = $package->getPrettyName();
        $fs = new Filesystem();

        // Prepare mappings to store for later removal
        $mappings = [];

        // Ensure the public/e directory exists
        $publicDir = $this->webroot . '/e/' . $packageName;
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        foreach ($publicFiles as $source) {
            $sourcePath = $installPath . '/' . $source;
            $relativePath = $source;
            $target = $publicDir . '/' . $relativePath;
            $mappings[$source] = $target;

            if (!file_exists($sourcePath)) {
                $this->io->writeError(sprintf(
                    '<warning>Source path %s does not exist for package %s</warning>',
                    $sourcePath,
                    $packageName
                ));
                continue;
            }

            // Create target directory if it doesn't exist
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Copy file or directory
            if (is_dir($sourcePath)) {
                $fs->copy($sourcePath, $target);
                $this->io->write(sprintf(
                    '<info>Copied directory from %s to %s</info>',
                    $sourcePath,
                    $target
                ));
            } else {
                copy($sourcePath, $target);
                $this->io->write(sprintf(
                    '<info>Copied file from %s to %s</info>',
                    $sourcePath,
                    $target
                ));
            }
        }

        // Store the mappings for later removal during uninstall
        if (!empty($mappings)) {
            $this->storeFileMappings($package, $mappings);
        }
    }

    /**
     * Store file mappings for later removal
     */
    private function storeFileMappings(PackageInterface $package, array $mappings): void
    {
        $packageName = $package->getPrettyName();
        $mappingsFile = $this->getMappingsFilePath();

        $allMappings = [];
        if (file_exists($mappingsFile)) {
            $allMappings = json_decode(file_get_contents($mappingsFile), true) ?: [];
        }

        $allMappings[$packageName] = $mappings;

        file_put_contents($mappingsFile, json_encode($allMappings, JSON_PRETTY_PRINT));
    }

    /**
     * Remove files that were copied from package
     */
    private function removePackageFiles(PackageInterface $package): void
    {
        $packageName = $package->getPrettyName();
        $mappingsFile = $this->getMappingsFilePath();

        if (!file_exists($mappingsFile)) {
            return;
        }

        $allMappings = json_decode(file_get_contents($mappingsFile), true) ?: [];

        if (!isset($allMappings[$packageName])) {
            return;
        }

        $mappings = $allMappings[$packageName];
        $fs = new Filesystem();

        foreach ($mappings as $source => $target) {
            if (file_exists($target)) {
                $fs->remove($target);
                $this->io->write(sprintf(
                    '<info>Removed %s during uninstallation of %s</info>',
                    $target,
                    $packageName
                ));
            }
        }

        // Remove the package directory if it exists and is empty
        $packageDir = $this->webroot . '/e/' . $packageName;
        if (is_dir($packageDir) && $this->isDirEmpty($packageDir)) {
            $fs->removeDirectory($packageDir);
            $this->io->write(sprintf(
                '<info>Removed empty directory %s during uninstallation of %s</info>',
                $packageDir,
                $packageName
            ));

            // Also try to remove the vendor directory if it's empty
            $vendorName = explode('/', $packageName)[0];
            $vendorDir = $this->webroot . '/e/' . $vendorName;
            if (is_dir($vendorDir) && $this->isDirEmpty($vendorDir)) {
                $fs->removeDirectory($vendorDir);
                $this->io->write(sprintf(
                    '<info>Removed empty vendor directory %s</info>',
                    $vendorDir
                ));
            }
        }

        // Remove this package from the mappings file
        unset($allMappings[$packageName]);
        file_put_contents($mappingsFile, json_encode($allMappings, JSON_PRETTY_PRINT));
    }

    /**
     * Check if a directory is empty
     */
    private function isDirEmpty(string $dir): bool
    {
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * Get the path to the file mappings storage
     */
    private function getMappingsFilePath(): string
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        return $vendorDir . '/aikeedo-file-mappings.json';
    }
}
