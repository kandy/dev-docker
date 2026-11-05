<?php

declare(strict_types=1);

// Runs inside app via stdin; the infrastructure checkout need not be mounted.
$key = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/D', $key)) {
    throw new InvalidArgumentException('Invalid test installation fingerprint.');
}
$root = '/magento/dev/tests/integration';
$directory = "$root/tmp/mdev-fast/$key";
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create fast test configuration directory.');
}
$codeKey = $argv[3] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/D', $codeKey)) {
    throw new InvalidArgumentException('Invalid test source fingerprint.');
}
if (!is_dir('/mdev-etc')) {
    throw new RuntimeException('The isolated test configuration mount is required.');
}
$etcFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('/mdev-etc', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($etcFiles as $etcFile) {
    $relative = substr($etcFile->getPathname(), strlen('/mdev-etc/'));
    if ($relative === 'env.php') {
        continue;
    }
    $target = "/magento/app/etc/$relative";
    if ($etcFile->isDir()) {
        if (!is_dir($target) && !mkdir($target, 0755, true)) {
            throw new RuntimeException("Cannot create isolated configuration directory $target.");
        }
    } elseif (!copy($etcFile->getPathname(), $target)) {
        throw new RuntimeException("Cannot copy isolated configuration file $target.");
    }
}
$codeMarker = "$root/tmp/mdev-fast/code-key";
$refreshCode = !is_file("$root/tmp/mdev-fast/active")
    || trim(file_get_contents("$root/tmp/mdev-fast/active")) !== $key
    || !is_file($codeMarker) || trim(file_get_contents($codeMarker)) !== $codeKey;
printf("Integration source snapshot: %s\n", $refreshCode ? 'refreshing' : 'reusing');
if ($refreshCode) {
    // Module registration must wait until the edition code volumes are populated.
    require '/magento/vendor/composer/ClassLoader.php';
    $loader = new Composer\Autoload\ClassLoader('/magento/vendor');
    foreach (require '/magento/vendor/composer/autoload_psr4.php' as $namespace => $paths) {
        $loader->setPsr4($namespace, $paths);
    }
    $loader->addClassMap(require '/magento/vendor/composer/autoload_classmap.php');
    $loader->register();
    $filesystem = new Symfony\Component\Filesystem\Filesystem();
    $filesystem->remove($codeMarker);
    $fixtureModules = [];
    $fixtureSources = array_merge(
        glob("$root/_files/Magento/*", GLOB_ONLYDIR),
        glob('/mdev-code/*/*/Test/_files/Magento/*', GLOB_ONLYDIR),
        glob('/mdev-source/ee/app/code/*/*/Test/_files/Magento/*', GLOB_ONLYDIR),
        glob('/mdev-source/b2b/app/code/*/*/Test/_files/Magento/*', GLOB_ONLYDIR)
    );
    foreach ($fixtureSources as $fixtureSource) {
        $fixtureModules['Magento/' . basename($fixtureSource)] = true;
    }
    $sources = [
        '/mdev-code' => '/magento/app/code',
        '/mdev-source/ee/app/code' => '/magento/ee/app/code',
        '/mdev-source/b2b/app/code' => '/magento/b2b/app/code',
    ];
    foreach ($sources as $sourceRoot => $targetRoot) {
        if (!is_dir($sourceRoot)) {
            continue;
        }
        $modules = [];
        foreach (glob("$sourceRoot/*/*") as $sourceModule) {
            if (!is_dir($sourceModule) && !is_link($sourceModule)) {
                continue;
            }
            $relative = substr($sourceModule, strlen($sourceRoot) + 1);
            if (isset($fixtureModules[$relative])) {
                continue;
            }
            $targetModule = "$targetRoot/$relative";
            $modules[$targetModule] = true;
            if (is_link($sourceModule)) {
                if (is_link($targetModule) && readlink($targetModule) === readlink($sourceModule)) {
                    continue;
                }
                $filesystem->remove($targetModule);
                $filesystem->mkdir(dirname($targetModule));
                $filesystem->symlink(readlink($sourceModule), $targetModule);
            } else {
                if (is_link($targetModule)) {
                    $filesystem->remove($targetModule);
                }
                $filesystem->mirror($sourceModule, $targetModule, null, ['override' => true, 'delete' => true]);
            }
        }
        foreach (glob("$targetRoot/*/*") as $targetModule) {
            if (!isset($modules[$targetModule])) {
                $filesystem->remove($targetModule);
            }
        }
    }
    $filesystem->remove(['/magento/generated/code', '/magento/generated/metadata']);
    $filesystem->remove(glob("$directory/sandbox-*/generated/code"));
    $filesystem->remove(glob("$directory/sandbox-*/var/cache"));
    if (file_put_contents($codeMarker, $codeKey) === false) {
        throw new RuntimeException('Cannot record test source fingerprint.');
    }
}
$source = is_file("$root/etc/install-config-mysql.php")
    ? "$root/etc/install-config-mysql.php" : "$root/etc/install-config-mysql.php.dist";
$install = '<?php return array_replace(require ' . var_export($source, true) . ', '
    . "['db-host' => 'test-db', 'db-name' => 'mdev_fast', 'db-user' => 'root', "
    . "'db-password' => getenv('MDEV_TEST_DB_PASSWORD'), 'db-prefix' => '']);\n";
if (file_put_contents("$directory/install.php", $install) === false) {
    throw new RuntimeException('Cannot write isolated test install configuration.');
}

$active = "$root/tmp/mdev-fast/active";
$reuse = is_file($active) && trim(file_get_contents($active)) === $key && ($argv[2] ?? '0') !== '1';
foreach (['integration', 'rest', 'graphql'] as $suite) {
    $base = $suite === 'integration' ? $root : '/magento/dev/tests/api-functional';
    $config = $suite === 'integration' ? 'phpunit.xml.dist' : "phpunit_$suite.xml.dist";
    $xml = new DOMDocument();
    if (!$xml->load("$base/$config")) {
        throw new RuntimeException("Cannot load $suite PHPUnit configuration.");
    }
    $xpath = new DOMXPath($xml);
    // PHPUnit resolves these paths relative to the generated configuration.
    $paths = '//@bootstrap | //directory | //file | //exclude[not(*)] | //includePath'
        . ' | //parameter[@name="config"]/@value';
    foreach ($xpath->query($paths) as $node) {
        $value = $node->nodeValue;
        if ($value !== '' && $value[0] !== '/') {
            $node->nodeValue = "$base/$value";
        }
    }
    $constants = [
        'TESTS_CLEANUP' => $suite === 'integration' && !$reuse ? 'enabled' : 'disabled',
        'TESTS_INSTALL_CONFIG_FILE' => ($suite === 'integration' ? '' : '../integration/')
            . "tmp/mdev-fast/$key/install.php",
        'TESTS_TEMP_DIR' => $directory,
        'TESTS_GLOBAL_CONFIG_DIR' => '../../../app/etc',
    ];
    if ($suite !== 'integration') {
        $constants += [
            'TESTS_MAGENTO_INSTALLATION' => 'disabled',
            'TESTS_BASE_URL' => 'http://test-app:8080/',
            'TESTS_WEB_API_ADAPTER' => 'rest',
            'TESTS_MAGENTO_MODE' => 'developer',
        ];
    }
    $php = $xpath->query('/phpunit/php')->item(0);
    foreach ($constants as $name => $value) {
        $node = $xpath->query("//const[@name='$name']")->item(0);
        if ($node === null) {
            $node = $php->appendChild($xml->createElement('const'));
            $node->setAttribute('name', $name);
        }
        $node->setAttribute('value', $value);
    }
    $allure = $xpath->query('//bootstrap[@class="Qameta\Allure\PHPUnit\AllureExtension"]/parameter[@name="config"]')->item(0);
    if ($allure !== null) {
        $reportConfig = '<?php require_once "/magento/dev/tests/config/AllureConfig.php";'
            . ' return getAllureConfig(' . var_export("$directory/allure-$suite", true) . ');';
        if (file_put_contents("$directory/allure-$suite.php", $reportConfig) === false) {
            throw new RuntimeException("Cannot write isolated $suite report configuration.");
        }
        $allure->setAttribute('value', "$directory/allure-$suite.php");
    }
    if ($suite === 'integration') {
        $bootstrap = '<?php require ' . var_export("$root/framework/bootstrap.php", true) . '; '
            . 'if (file_put_contents(' . var_export($active, true) . ', ' . var_export($key, true) . ') === false) {'
            . ' throw new \RuntimeException("Cannot record completed test installation."); }';
        if (file_put_contents("$directory/bootstrap.php", $bootstrap) === false) {
            throw new RuntimeException('Cannot write fast test bootstrap.');
        }
        $xml->documentElement->setAttribute('bootstrap', "$directory/bootstrap.php");
    }
    $output = $suite === 'integration' ? 'phpunit.xml' : "phpunit_$suite.xml";
    if ($xml->save("$directory/$output") === false) {
        throw new RuntimeException("Cannot write fast $suite PHPUnit configuration.");
    }
}
printf("Integration installation: %s\n", $reuse ? 'reusing' : 'rebuilding');
printf("Integration config: %s/phpunit.xml\n", $directory);
