<?php

declare(strict_types=1);

$key = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/D', $key) || !is_dir('/mdev-etc')) {
    throw new InvalidArgumentException('An isolated test installation is required for API tests.');
}
$root = '/magento/dev/tests/integration/tmp/mdev-fast';
$directory = "$root/$key";
if (!is_file("$root/active") || trim(file_get_contents("$root/active")) !== $key) {
    throw new RuntimeException('Integration bootstrap must succeed before running API tests.');
}
$environments = glob("$directory/sandbox-*/etc/env.php");
if (count($environments) !== 1) {
    throw new RuntimeException('Cannot identify the installed test sandbox.');
}
$environment = require $environments[0];
$connection = $environment['db']['connection']['default'] ?? [];
if (($connection['host'] ?? '') !== 'test-db' || ($connection['dbname'] ?? '') !== 'mdev_fast') {
    throw new RuntimeException('Refusing to run API tests against a non-disposable database.');
}
// An interrupted API run must not leave a dirty installation eligible for reuse.
if (!unlink("$root/active")) {
    throw new RuntimeException('Cannot invalidate installation reuse before API tests.');
}
foreach (['env.php', 'config.php'] as $file) {
    $source = dirname($environments[0]) . "/$file";
    if (!copy($source, "/magento/app/etc/$file") || !chmod("/magento/app/etc/$file", 0600)) {
        throw new RuntimeException("Cannot prepare isolated API deployment configuration $file.");
    }
}
require '/magento/vendor/autoload.php';
$filesystem = new Symfony\Component\Filesystem\Filesystem();
$filesystem->remove(['/magento/var/cache', '/magento/var/page_cache']);
$database = new PDO(
    'mysql:host=test-db;dbname=mdev_fast',
    $connection['username'],
    $connection['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$statement = $database->prepare(
    'INSERT INTO core_config_data (scope, scope_id, path, value) VALUES (?, ?, ?, ?)'
    . ' ON DUPLICATE KEY UPDATE value = VALUES(value)'
);
foreach (['web/unsecure/base_url', 'web/secure/base_url'] as $path) {
    $statement->execute(['default', 0, $path, 'http://test-app:8080/']);
}
printf("API environment: isolated test-app:8080 / test-db.mdev_fast\n");
