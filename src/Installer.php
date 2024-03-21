<?php

declare(strict_types=1);

namespace Aikeedo\Composer;

use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;

class Installer extends LibraryInstaller implements InstallerInterface
{
    /** @inheritDoc */
    public function supports(string $packageType)
    {
        $supported = [
            'aikeedo-plugin',
            'aikeedo-theme',
        ];

        return in_array($packageType, $supported);
    }

    /** @inheritDoc */
    public function getInstallPath(PackageInterface $package)
    {
        $dir = $package->getType() == 'aikeedo-theme' ? 'themes' : 'plugins';

        return 'public/content/' . $dir . '/' . $package->getPrettyName();
    }
}
