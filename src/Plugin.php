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
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageUpdate',
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
     * Handle post package update event
     */
    public function onPackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAikeedoPackage($package)) {
            return;
        }

        // Remove old files first, then copy new ones
        $this->removePackageFiles($package);
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
     *
     * Target path resolution follows "Model A" where target is ALWAYS the final destination path:
     *
     * | Target Format | Result                                      | Example                           |
     * |---------------|---------------------------------------------|-----------------------------------|
     * | "file.js"     | Package dir + target                        | public/e/{vendor}/{pkg}/file.js   |
     * | "/file.js"    | Webroot + target                            | public/file.js                    |
     * | "."           | Package dir + source basename               | public/e/{vendor}/{pkg}/src       |
     * | "/." or "/"   | Webroot + source basename                   | public/src                        |
     * | null          | Package dir + source basename               | public/e/{vendor}/{pkg}/src       |
     * | "dir/*"       | Glob: contents copied to target directory   | public/dist/...                   |
     * | (string)      | Legacy: package dir + full source path      | public/e/.../path/to/file         |
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

        // Default public directory for this package: {webroot}/e/{vendor}/{package}
        $defaultPublicDir = $this->webroot . '/e/' . $packageName;

        foreach ($publicFiles as $entry) {
            try {
                // Normalize entry to source/target pair
                if (is_string($entry)) {
                    // Legacy format: simple string path preserves full path structure
                    // Example: "widget/dist/file.js" → public/e/{vendor}/{pkg}/widget/dist/file.js
                    $source = $entry;
                    $targetPath = $defaultPublicDir . '/' . $entry;
                } elseif (is_array($entry) && isset($entry['source'])) {
                    // New format: {source, target} - target is the final destination path
                    $source = $entry['source'];
                    $target = $entry['target'] ?? null;

                    // Determine target path based on target format (Model A: target IS the final path)
                    if ($target === null) {
                        // No target: package dir + source basename
                        // Example: {"source": "dist"} → public/e/{vendor}/{pkg}/dist
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $defaultPublicDir . '/' . basename($sourceBase);
                    } elseif ($target === '/.' || $target === '/') {
                        // Target "/." or "/": webroot + source basename (shorthand)
                        // Example: {"source": "dist", "target": "/."} → public/dist
                        // Example: {"source": "dist", "target": "/"} → public/dist
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $this->webroot . '/' . basename($sourceBase);
                    } elseif ($target === '.') {
                        // Target ".": package dir + source basename (same as null)
                        // Example: {"source": "dist", "target": "."} → public/e/{vendor}/{pkg}/dist
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $defaultPublicDir . '/' . basename($sourceBase);
                    } elseif (strpos($target, '/') === 0) {
                        // Target starts with "/": relative to webroot (target IS the final path)
                        // Example: {"source": "dist", "target": "/assets"} → public/assets
                        $targetPath = $this->webroot . '/' . ltrim($target, '/');
                    } else {
                        // Target without leading "/": relative to package dir (target IS the final path)
                        // Example: {"source": "dist", "target": "assets"} → public/e/{vendor}/{pkg}/assets
                        $targetPath = $defaultPublicDir . '/' . ltrim($target, '/');
                    }
                } else {
                    $this->io->writeError(sprintf(
                        '<warning>Invalid public entry format in package %s</warning>',
                        $packageName
                    ));
                    continue;
                }

                // Check if source contains glob patterns
                if ($this->containsGlobPattern($source)) {
                    $this->copyGlobPattern($installPath, $source, $targetPath, $fs, $packageName, $mappings);
                } else {
                    $sourcePath = $installPath . '/' . $source;

                    if (!file_exists($sourcePath)) {
                        $this->io->writeError(sprintf(
                            '<warning>Source path %s does not exist for package %s</warning>',
                            $sourcePath,
                            $packageName
                        ));
                        continue;
                    }

                    // Create target directory if it doesn't exist
                    $targetDir = is_dir($sourcePath) ? $targetPath : dirname($targetPath);
                    if (!is_dir($targetDir)) {
                        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                            throw new \RuntimeException(sprintf(
                                'Failed to create target directory: %s',
                                $targetDir
                            ));
                        }
                    }

                    // Copy file or directory
                    if (is_dir($sourcePath)) {
                        $fs->copy($sourcePath, $targetPath);
                        $this->io->write(sprintf(
                            '<info>Copied directory from %s to %s</info>',
                            $sourcePath,
                            $targetPath
                        ));
                    } else {
                        if (!copy($sourcePath, $targetPath)) {
                            throw new \RuntimeException(sprintf(
                                'Failed to copy file from %s to %s',
                                $sourcePath,
                                $targetPath
                            ));
                        }
                        $this->io->write(sprintf(
                            '<info>Copied file from %s to %s</info>',
                            $sourcePath,
                            $targetPath
                        ));
                    }

                    $mappings[$source] = $targetPath;
                }
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Error processing entry for package %s: %s</error>',
                    $packageName,
                    $e->getMessage()
                ));
                continue;
            }
        }

        // Store the mappings for later removal during uninstall
        if (!empty($mappings)) {
            try {
                $this->storeFileMappings($package, $mappings);
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Failed to store file mappings for package %s: %s</error>',
                    $packageName,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Check if a path contains glob patterns
     */
    private function containsGlobPattern(string $path): bool
    {
        return strpos($path, '*') !== false || strpos($path, '?') !== false || strpos($path, '[') !== false;
    }

    /**
     * Get base path from a glob pattern (removes wildcards for basename calculation)
     */
    private function getBasePathFromPattern(string $pattern): string
    {
        // Remove trailing glob patterns like /*, /**/*, etc.
        $pattern = preg_replace('/\/\*+\/?$/', '', $pattern);
        // Remove any remaining wildcards for basename
        $pattern = preg_replace('/[?*\[\]]/', '', $pattern);
        return $pattern;
    }

    /**
     * Copy files matching a glob pattern
     */
    private function copyGlobPattern(
        string $installPath,
        string $sourcePattern,
        string $targetBase,
        Filesystem $fs,
        string $packageName,
        array &$mappings
    ): void {
        $fullPattern = $installPath . '/' . $sourcePattern;

        // Handle special case: pattern ending with '/*' (copy directory contents)
        if (preg_match('/\/\*+$/', $sourcePattern)) {
            $sourceDir = $installPath . '/' . preg_replace('/\/\*+$/', '', $sourcePattern);
            if (!is_dir($sourceDir)) {
                $this->io->writeError(sprintf(
                    '<warning>Source directory %s does not exist for package %s</warning>',
                    $sourceDir,
                    $packageName
                ));
                return;
            }

            // Copy contents of directory recursively
            $this->copyDirectoryContents($sourceDir, $targetBase, $fs, $packageName, $mappings);
            return;
        }

        // Use glob() to find matching files
        $matches = glob($fullPattern);

        if ($matches === false) {
            $this->io->writeError(sprintf(
                '<warning>Invalid glob pattern %s for package %s</warning>',
                $sourcePattern,
                $packageName
            ));
            return;
        }

        if (empty($matches)) {
            $this->io->write(sprintf(
                '<info>No files matched pattern %s for package %s</info>',
                $sourcePattern,
                $packageName
            ), true, IOInterface::VERBOSE);
            return;
        }

        // Determine if we're copying to a directory or file
        $isTargetDir = is_dir($targetBase) || (count($matches) > 1 || is_dir($matches[0]));

        foreach ($matches as $sourcePath) {
            try {
                if (!file_exists($sourcePath)) {
                    continue;
                }

                // Calculate target path
                if ($isTargetDir) {
                    // If target is a directory, preserve relative path structure
                    $relativePath = substr($sourcePath, strlen($installPath) + 1);
                    $targetPath = $targetBase . '/' . $relativePath;
                } else {
                    // Single file, use target as-is
                    $targetPath = $targetBase;
                }

                // Create target directory if needed
                $targetDir = is_dir($sourcePath) ? $targetPath : dirname($targetPath);
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        throw new \RuntimeException(sprintf(
                            'Failed to create target directory: %s',
                            $targetDir
                        ));
                    }
                }

                // Copy file or directory
                if (is_dir($sourcePath)) {
                    $fs->copy($sourcePath, $targetPath);
                    $this->io->write(sprintf(
                        '<info>Copied directory from %s to %s</info>',
                        $sourcePath,
                        $targetPath
                    ), true, IOInterface::VERBOSE);
                } else {
                    if (!copy($sourcePath, $targetPath)) {
                        throw new \RuntimeException(sprintf(
                            'Failed to copy file from %s to %s',
                            $sourcePath,
                            $targetPath
                        ));
                    }
                    $this->io->write(sprintf(
                        '<info>Copied file from %s to %s</info>',
                        $sourcePath,
                        $targetPath
                    ), true, IOInterface::VERBOSE);
                }

                $mappings[$sourcePath] = $targetPath;
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Error copying %s: %s</error>',
                    $sourcePath,
                    $e->getMessage()
                ));
                continue;
            }
        }
    }

    /**
     * Copy contents of a directory (for glob patterns) - recursively
     */
    private function copyDirectoryContents(string $sourceDir, string $targetDir, Filesystem $fs, string $packageName, array &$mappings): void
    {
        try {
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    throw new \RuntimeException(sprintf(
                        'Failed to create target directory: %s',
                        $targetDir
                    ));
                }
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                try {
                    $sourcePath = $item->getPathname();
                    $relativePath = substr($sourcePath, strlen($sourceDir) + 1);
                    $targetPath = $targetDir . '/' . $relativePath;

                    if ($item->isDir()) {
                        if (!is_dir($targetPath)) {
                            if (!mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                                throw new \RuntimeException(sprintf(
                                    'Failed to create directory: %s',
                                    $targetPath
                                ));
                            }
                        }
                    } else {
                        $targetFileDir = dirname($targetPath);
                        if (!is_dir($targetFileDir)) {
                            if (!mkdir($targetFileDir, 0755, true) && !is_dir($targetFileDir)) {
                                throw new \RuntimeException(sprintf(
                                    'Failed to create directory: %s',
                                    $targetFileDir
                                ));
                            }
                        }
                        if (!copy($sourcePath, $targetPath)) {
                            throw new \RuntimeException(sprintf(
                                'Failed to copy file from %s to %s',
                                $sourcePath,
                                $targetPath
                            ));
                        }
                    }

                    $mappings[$sourcePath] = $targetPath;
                } catch (\Exception $e) {
                    $this->io->writeError(sprintf(
                        '<error>Error copying %s: %s</error>',
                        $item->getPathname(),
                        $e->getMessage()
                    ));
                    continue;
                }
            }

            $this->io->write(sprintf(
                '<info>Copied directory contents from %s to %s</info>',
                $sourceDir,
                $targetDir
            ), true, IOInterface::VERBOSE);
        } catch (\Exception $e) {
            $this->io->writeError(sprintf(
                '<error>Error copying directory contents from %s to %s: %s</error>',
                $sourceDir,
                $targetDir,
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * Store file mappings for later removal
     * Uses relative paths from webroot for portability
     */
    private function storeFileMappings(PackageInterface $package, array $mappings): void
    {
        $packageName = $package->getPrettyName();
        $mappingsFile = $this->getMappingsFilePath();

        try {
            $allMappings = [];
            if (file_exists($mappingsFile)) {
                $content = file_get_contents($mappingsFile);
                if ($content === false) {
                    throw new \RuntimeException(sprintf(
                        'Failed to read mappings file: %s',
                        $mappingsFile
                    ));
                }
                $decoded = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException(sprintf(
                        'Failed to decode mappings file: %s',
                        json_last_error_msg()
                    ));
                }
                $allMappings = $decoded ?: [];
            }

            // Convert absolute paths to relative paths from webroot for portability
            $relativeMappings = [];
            foreach ($mappings as $source => $target) {
                // Store relative path from webroot
                // Handle both absolute paths and paths that already include webroot
                $webrootPrefix = $this->webroot . '/';
                if (strpos($target, $webrootPrefix) === 0) {
                    // Path starts with webroot, remove it
                    $relativeTarget = substr($target, strlen($webrootPrefix));
                } elseif (strpos($target, '/') === 0) {
                    // Absolute path but doesn't start with webroot - this shouldn't happen
                    // Try to extract relative part after webroot
                    $webrootPos = strpos($target, $webrootPrefix);
                    if ($webrootPos !== false) {
                        $relativeTarget = substr($target, $webrootPos + strlen($webrootPrefix));
                    } else {
                        // Fallback: use as-is but log warning
                        $this->io->write(sprintf(
                            '<warning>Target path %s does not contain webroot %s, storing as-is</warning>',
                            $target,
                            $this->webroot
                        ), true, IOInterface::VERBOSE);
                        $relativeTarget = $target;
                    }
                } else {
                    // Already relative, use as-is
                    $relativeTarget = $target;
                }
                $relativeMappings[$source] = $relativeTarget;
            }

            $allMappings[$packageName] = $relativeMappings;

            $json = json_encode($allMappings, JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new \RuntimeException(sprintf(
                    'Failed to encode mappings: %s',
                    json_last_error_msg()
                ));
            }

            if (file_put_contents($mappingsFile, $json) === false) {
                throw new \RuntimeException(sprintf(
                    'Failed to write mappings file: %s',
                    $mappingsFile
                ));
            }
        } catch (\Exception $e) {
            $this->io->writeError(sprintf(
                '<error>Error storing file mappings: %s</error>',
                $e->getMessage()
            ));
            throw $e;
        }
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

        try {
            $content = file_get_contents($mappingsFile);
            if ($content === false) {
                $this->io->writeError(sprintf(
                    '<warning>Failed to read mappings file: %s</warning>',
                    $mappingsFile
                ));
                return;
            }

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->io->writeError(sprintf(
                    '<warning>Failed to decode mappings file: %s</warning>',
                    json_last_error_msg()
                ));
                return;
            }

            $allMappings = $decoded ?: [];

            // Try both pretty name and canonical name for package lookup
            $packageCanonicalName = $package->getName();
            $packageKey = null;

            if (isset($allMappings[$packageName])) {
                $packageKey = $packageName;
            } elseif (isset($allMappings[$packageCanonicalName])) {
                $packageKey = $packageCanonicalName;
            } else {
                // Try to find by case-insensitive match
                foreach ($allMappings as $key => $value) {
                    if (strtolower($key) === strtolower($packageName) || strtolower($key) === strtolower($packageCanonicalName)) {
                        $packageKey = $key;
                        $this->io->write(sprintf(
                            '<info>Found package mapping with different case: %s (looking for %s)</info>',
                            $key,
                            $packageName
                        ), true, IOInterface::VERBOSE);
                        break;
                    }
                }
            }

            if ($packageKey === null) {
                $this->io->write(sprintf(
                    '<warning>No file mappings found for package %s (tried %s and %s)</warning>',
                    $packageName,
                    $packageName,
                    $packageCanonicalName
                ), true, IOInterface::VERBOSE);
                return;
            }

            $mappings = $allMappings[$packageKey];
            $fs = new Filesystem();

            foreach ($mappings as $source => $relativeTarget) {
                try {
                    // Convert relative path back to absolute
                    // Handle both relative paths and paths that already include webroot
                    if (strpos($relativeTarget, $this->webroot . '/') === 0) {
                        // Already contains webroot prefix
                        $target = $relativeTarget;
                    } elseif (strpos($relativeTarget, '/') === 0) {
                        // Absolute path
                        $target = $relativeTarget;
                    } else {
                        // Relative path, prepend webroot
                        $target = $this->webroot . '/' . ltrim($relativeTarget, '/');
                    }

                    if (file_exists($target)) {
                        $fs->remove($target);
                        $this->io->write(sprintf(
                            '<info>Removed %s during uninstallation of %s</info>',
                            $target,
                            $packageName
                        ));
                    } else {
                        $this->io->write(sprintf(
                            '<info>File %s does not exist (may have been already removed)</info>',
                            $target
                        ), true, IOInterface::VERBOSE);
                    }
                } catch (\Exception $e) {
                    $this->io->writeError(sprintf(
                        '<error>Error removing %s: %s</error>',
                        $relativeTarget,
                        $e->getMessage()
                    ));
                    continue;
                }
            }

            // Remove the package directory if it exists and is empty
            try {
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
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<warning>Error removing directories: %s</warning>',
                    $e->getMessage()
                ));
            }

            // Remove this package from the mappings file using the correct key
            unset($allMappings[$packageKey]);
            $json = json_encode($allMappings, JSON_PRETTY_PRINT);
            if ($json !== false) {
                file_put_contents($mappingsFile, $json);
            }
        } catch (\Exception $e) {
            $this->io->writeError(sprintf(
                '<error>Error removing package files: %s</error>',
                $e->getMessage()
            ));
        }
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
