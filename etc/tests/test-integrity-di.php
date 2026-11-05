<?php

declare(strict_types=1);

namespace Mdev\Fast;

use Magento\Framework\Component\ComponentRegistrar;

require_once '/magento/dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/DiConfigFilesTest.php';

class DiConfigFilesTest extends \Magento\Test\Integrity\Modular\DiConfigFilesTest
{
    public static function linearFilesProvider()
    {
        $files = parent::linearFilesProvider();
        $modules = getenv('MDEV_TEST_INTEGRITY_MODULES');
        if ($modules === false || $modules === '') {
            throw new \RuntimeException('Changed modules are required for focused DI integrity.');
        }
        $registrar = new ComponentRegistrar();
        $paths = [];
        foreach (array_unique(explode(',', $modules)) as $module) {
            $path = $registrar->getPath(ComponentRegistrar::MODULE, $module);
            if ($path === null) {
                throw new \RuntimeException("Unknown module for focused DI integrity: $module.");
            }
            $paths[] = "$path/";
        }
        $primary = self::$_primaryFiles->toArray();
        return array_values(array_filter($files, static function (array $row) use ($paths, $primary): bool {
            $file = BP . $row[0];
            if (isset($primary[$file])) {
                return true;
            }
            foreach ($paths as $path) {
                if (str_starts_with($file, $path)) {
                    return true;
                }
            }
            return false;
        }));
    }

    public static function mixedFilesProvider()
    {
        // Keep every native whole-configuration merge; file-level checks are selected above.
        return array_filter(parent::mixedFilesProvider(), static fn($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
