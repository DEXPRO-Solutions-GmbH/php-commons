<?php

declare(strict_types=1);

namespace DexproSolutionsGmbh\PhpCommons\OS;

/**
 * OS is possibly the simplest helper class you can imagine: It helps you in
 * checking if your operating system is linux, windows or something unknown.
 */
class OS
{
    public const LINUX = 'linux';

    public const WINDOWS = 'windows';

    public const UNKNOWN = 'unknown';

    public static function getOperatingSystem(): string
    {
        switch (PHP_OS) {
            case 'WIN32':
            case 'WINNT':
            case 'Windows':
                $system = self::WINDOWS;
                break;
            case 'Linux':
                $system = self::LINUX;
                break;
            default:
                $system = self::UNKNOWN;
                break;
        }

        return $system;
    }

    public static function isWindows(): bool
    {
        return self::getOperatingSystem() === self::WINDOWS;
    }

    public static function isLinux(): bool
    {
        return self::getOperatingSystem() === self::LINUX;
    }
}
