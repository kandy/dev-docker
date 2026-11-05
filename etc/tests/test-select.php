<?php

declare(strict_types=1);

function mdevTestSelectSurfaces(string $root, array $changes): array
{
    $selected = ['integrity' => [], 'rest' => [], 'graphql' => [], 'block' => [], 'module' => [], 'warning' => []];
    $integrity = 'dev/tests/integration/testsuite/Magento/Test/Integrity/';
    $fullBlockIntegrity = in_array($integrity . 'Modular/BlockInstantiationTest.php', $changes, true);
    $fullDiIntegrity = in_array($integrity . 'Modular/DiConfigFilesTest.php', $changes, true)
        || preg_grep('#^(?:lib/internal/|app/etc/)#', $changes) !== [];
    $files = [];
    $contents = [];
    $add = static function (string $suite, string $path) use (&$selected, $root): void {
        if (is_file("$root/$path")) {
            $selected[$suite][$path] = $path;
        }
    };
    $tests = static function (array $directories) use (&$files, $root): array {
        $result = [];
        foreach ($directories as $directory) {
            if (!isset($files[$directory])) {
                $files[$directory] = [];
                if (is_dir("$root/$directory")) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator("$root/$directory", FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        $path = substr($file->getPathname(), strlen($root) + 1);
                        if ($file->isFile() && str_ends_with($path, 'Test.php') && !str_contains($path, '/_files/')) {
                            $files[$directory][] = $path;
                        }
                    }
                }
            }
            $result = array_merge($result, $files[$directory]);
        }
        return array_unique($result);
    };
    $read = static function (string $path) use (&$contents, $root): string {
        if (!isset($contents[$path])) {
            $contents[$path] = is_file("$root/$path") ? file_get_contents("$root/$path") : '';
            if ($contents[$path] === false) {
                throw new RuntimeException("Cannot read $path for test selection.");
            }
        }
        return $contents[$path];
    };
    $configTests = [
        'acl.xml' => 'AclConfigFilesTest.php', 'di.xml' => 'DiConfigFilesTest.php',
        'menu.xml' => 'MenuConfigFilesTest.php', 'system.xml' => 'SystemConfigFilesTest.php',
        'events.xml' => 'EventConfigFilesTest.php', 'routes.xml' => 'RouteConfigFilesTest.php',
        'crontab.xml' => 'CrontabConfigFilesTest.php', 'indexer.xml' => 'IndexerConfigFilesTest.php',
        'mview.xml' => 'MviewConfigFilesTest.php', 'import.xml' => 'ImportConfigFilesTest.php',
        'export.xml' => 'ExportConfigFilesTest.php', 'resources.xml' => 'ResourcesConfigFilesTest.php',
        'widget.xml' => 'WidgetConfigFilesTest.php', 'view.xml' => 'ViewConfigFilesTest.php',
        'payment.xml' => 'PaymentConfigFilesTest.php', 'product_types.xml' => 'ProductTypesConfigFilesTest.php',
        'product_options.xml' => 'ProductOptionsConfigFilesTest.php', 'fieldset.xml' => 'FieldsetConfigFilesTest.php',
        'cache.xml' => 'CacheFilesTest.php',
    ];
    foreach ($changes as $path) {
        if (str_contains($path, '/_files/')) {
            continue;
        }
        if (str_starts_with($path, $integrity)) {
            $add('integrity', $path);
            continue;
        }
        if (str_ends_with($path, 'Test.php')
            && (str_starts_with($path, 'dev/tests/api-functional/testsuite/')
                || str_contains($path, '/Test/Api/'))
        ) {
            $suite = str_contains($path, '/GraphQl/')
                || preg_match('/extends\s+(?:\\\\?[\w\\\\]+\\\\)?GraphQlAbstract\b/', $read($path))
                ? 'graphql' : 'rest';
            $add($suite, $path);
            continue;
        }
        if (str_contains($path, '/Test/') || str_starts_with($path, 'dev/tests/')) {
            continue;
        }
        if (isset($configTests[basename($path)])) {
            $add('integrity', $integrity . 'Modular/' . $configTests[basename($path)]);
        }
        if (str_ends_with($path, '.php')) {
            $add('integrity', $integrity . 'Modular/DiConfigFilesTest.php');
            if (str_contains($path, '/Block/')) {
                $add('integrity', $integrity . 'Modular/BlockInstantiationTest.php');
            }
        }
        if (str_contains($path, '/layout/') && str_ends_with($path, '.xml')) {
            $add('integrity', $integrity . 'Modular/LayoutFilesTest.php');
        }
        if (str_ends_with($path, '.phtml')) {
            $add('integrity', $integrity . 'Modular/TemplateFilesTest.php');
        }
        if (!preg_match('#^app/code/([^/]+)/([^/]+)/(.*)$#', $path, $parts)) {
            continue;
        }
        [, $vendor, $module, $relative] = $parts;
        if (!$fullDiIntegrity && (str_ends_with($path, '.php') || basename($path) === 'di.xml')) {
            $selected['module'][] = "{$vendor}_{$module}";
        }
        if (!$fullBlockIntegrity && str_starts_with($relative, 'Block/') && str_ends_with($relative, '.php')) {
            $selected['block'][] = "{$vendor}_{$module}";
        }
        $moduleRoot = "app/code/$vendor/$module";
        $class = "$vendor\\$module\\" . str_replace('/', '\\', preg_replace('/\.php$/', '', $relative));
        $interfaces = [];
        $resolverImplementation = false;
        $source = str_ends_with($path, '.php') ? $read($path) : '';
        $implements = '';
        $collect = false;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_IMPLEMENTS) {
                $collect = true;
            } elseif ($collect && $token === '{') {
                break;
            } elseif ($collect && (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))) {
                $implements .= is_array($token) ? $token[1] : $token;
            }
        }
        if ($implements !== '') {
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $imports, PREG_SET_ORDER);
            $aliases = [];
            foreach ($imports as $import) {
                $aliases[$import[2] ?? substr($import[1], strrpos($import[1], '\\') + 1)] = $import[1];
            }
            foreach (explode(',', $implements) as $interface) {
                $interface = trim($interface);
                $first = explode('\\', $interface)[0];
                $namespace = substr($class, 0, strrpos($class, '\\'));
                $resolved = isset($aliases[$first])
                    ? $aliases[$first] . substr($interface, strlen($first))
                    : (str_starts_with($interface, '\\') ? ltrim($interface, '\\') : "$namespace\\$interface");
                if (str_contains($resolved, '\\Api\\')) {
                    $interfaces[] = $resolved;
                }
                $resolverImplementation = $resolverImplementation
                    || (str_starts_with($resolved, 'Magento\\Framework\\GraphQl\\Query\\')
                        && str_ends_with($resolved, 'ResolverInterface'));
            }
        }
        $graphql = str_contains($relative, '/Resolver/') || str_ends_with($relative, '.graphqls')
            || $resolverImplementation;
        $rest = str_starts_with($relative, 'Api/') || str_starts_with($relative, 'Model/ResourceModel/')
            || $interfaces !== [] || basename($path) === 'webapi.xml';
        foreach (['rest' => $rest, 'graphql' => $graphql] as $suite => $needed) {
            if (!$needed) {
                continue;
            }
            $apiModule = $suite === 'graphql' ? preg_replace('/GraphQl$/', '', $module) : $module;
            $directories = $suite === 'graphql'
                ? [
                    "dev/tests/api-functional/testsuite/$vendor/GraphQl/$apiModule",
                    "dev/tests/api-functional/testsuite/$vendor/GraphQl/$module",
                    "$moduleRoot/Test/Api",
                ]
                : ["dev/tests/api-functional/testsuite/$vendor/$module", "$moduleRoot/Test/Api"];
            if ($suite === 'rest') {
                foreach ($interfaces as $interface) {
                    $parts = explode('\\', $interface);
                    $directories[] = "dev/tests/api-functional/testsuite/{$parts[0]}/{$parts[1]}";
                    $directories[] = "app/code/{$parts[0]}/{$parts[1]}/Test/Api";
                }
            }
            $candidates = $tests($directories);
            $candidates = array_filter($candidates, static function (string $test) use ($suite, $read): bool {
                $isGraphql = str_contains($test, '/GraphQl/')
                    || preg_match('/extends\s+(?:\\\\?[\w\\\\]+\\\\)?GraphQlAbstract\b/', $read($test));
                return ($suite === 'graphql') === (bool)$isGraphql;
            });
            $names = [basename($path, '.php')];
            if ($suite === 'rest') {
                $names[] = str_replace('/', '', preg_replace('#^(?:Api/|Model/(?:ResourceModel/)?)#', '', $relative));
                foreach ($interfaces as $interface) {
                    $names[] = substr($interface, strrpos($interface, '\\') + 1);
                }
            }
            $names = array_map(static fn(string $name): string => preg_replace('/(?:\.php|Interface)$/', '', $name), $names);
            $matched = [];
            foreach ($candidates as $test) {
                $testName = basename($test, 'Test.php');
                if (in_array($testName, $names, true) || in_array(preg_replace('/Interface$/', '', $testName), $names, true)) {
                    $matched[$test] = $test;
                }
            }
            if ($matched === []) {
                $fields = [];
                if ($suite === 'graphql') {
                    $schema = "$moduleRoot/etc/schema.graphqls";
                    if (is_file("$root/$schema")) {
                        $document = GraphQL\Language\Parser::parse($read($schema));
                        foreach ($document->definitions as $definition) {
                            foreach ($definition->directives ?? [] as $directive) {
                                if ($directive->name->value !== 'typeResolver') {
                                    continue;
                                }
                                foreach ($directive->arguments as $argument) {
                                    if ($argument->name->value === 'class'
                                        && (ltrim($argument->value->value, '\\') === $class
                                            || $relative === 'etc/schema.graphqls')
                                    ) {
                                        $fields[] = $definition->name->value;
                                    }
                                }
                            }
                            foreach ($definition->fields ?? [] as $field) {
                                foreach ($field->directives as $directive) {
                                    if ($directive->name->value !== 'resolver') {
                                        continue;
                                    }
                                    foreach ($directive->arguments as $argument) {
                                        if ($argument->name->value === 'class'
                                            && (ltrim($argument->value->value, '\\') === $class
                                                || $relative === 'etc/schema.graphqls')
                                        ) {
                                            $fields[] = $field->name->value;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                foreach ($candidates as $test) {
                    $body = $read($test);
                    foreach (array_merge([$class], $suite === 'rest' ? $interfaces : []) as $reference) {
                        if (str_contains($body, $reference)) {
                            $matched[$test] = $test;
                        }
                    }
                    foreach ($fields as $field) {
                        if (preg_match('/\b' . preg_quote($field, '/') . '\b/', $body)) {
                            $matched[$test] = $test;
                        }
                    }
                }
            }
            if ($matched === [] && $suite === 'rest' && str_starts_with($relative, 'Model/ResourceModel/')) {
                $segments = explode('/', substr($relative, strlen('Model/ResourceModel/')));
                $entity = basename($relative, '.php');
                if (count($segments) > 1) {
                    $entity = $segments[0] === 'Eav' || $segments[0] === 'Entity'
                        ? pathinfo($segments[1], PATHINFO_FILENAME) : $segments[0];
                }
                foreach ($candidates as $test) {
                    if (str_contains(basename($test), $entity)) {
                        $matched[$test] = $test;
                    }
                }
            }
            foreach ($matched as $test) {
                $add($suite, $test);
            }
            if ($matched === []) {
                $selected['warning'][] = "No focused $suite test for $path; review its API coverage.";
            }
        }
    }
    foreach ($selected as &$paths) {
        $paths = array_values(array_unique($paths));
        sort($paths);
    }
    return $selected;
}

if (isset($argv[1])) {
    require rtrim($argv[1], '/') . '/vendor/autoload.php';
    foreach (mdevTestSelectSurfaces(rtrim($argv[1], '/'), array_slice($argv, 2)) as $suite => $paths) {
        foreach ($paths as $path) {
            printf("%s\t%s\n", $suite, $path);
        }
    }
}
