<?php

declare(strict_types=1);

namespace Aikeedo\Composer;

use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\PartialComposer;
use Override;

class Installer extends LibraryInstaller implements InstallerInterface
{
    /** @var string Path to plugins. Current default is set for back compatibility. */
    private string $path = 'public/content/plugins/';

    public function __construct(
        IOInterface $io,
        PartialComposer $composer,
        ?string $type = 'library'
    ) {
        parent::__construct($io, $composer, $type);

        $extra = $composer->getPackage()->getExtra();

        if (isset($extra['paths']['extensions'])) {
            $this->path = $extra['paths']['extensions'];
            // Ensure trailing slash
            if (substr($this->path, -1) !== '/') {
                $this->path .= '/';
            }
        }
    }

    #[Override]
    public function supports(string $packageType)
    {
        $supported = [
            'aikeedo-plugin',
            'aikeedo-theme',
        ];

        return in_array($packageType, $supported);
    }

    #[Override]
    public function getInstallPath(PackageInterface $package)
    {
        return $this->path . $package->getPrettyName();
    }
}
