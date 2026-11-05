<?php

declare(strict_types=1);

if (!function_exists('mdevTestSelectSurfaces')) {
    require __DIR__ . '/test-select.php';
}
require '/magento/vendor/autoload.php';

$fixture = sys_get_temp_dir() . '/mdev-selection-' . bin2hex(random_bytes(8));
$filesystem = new Symfony\Component\Filesystem\Filesystem();
$put = static function (string $path, string $body = '<?php') use ($fixture, $filesystem): void {
    $filesystem->mkdir(dirname("$fixture/$path"));
    if (file_put_contents("$fixture/$path", $body) === false) {
        throw new RuntimeException("Cannot create selector fixture $path.");
    }
};
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$select = static fn(array $changes): array => mdevTestSelectSurfaces($fixture, $changes);
$integrity = 'dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/';
$rest = 'dev/tests/api-functional/testsuite/Magento/Example/Api/';
$graphql = 'dev/tests/api-functional/testsuite/Magento/GraphQl/Example/';

try {
    foreach (['Di', 'Acl', 'Event', 'LayoutFiles', 'BlockInstantiation', 'TemplateFiles', 'CacheFiles'] as $name) {
        $put($integrity . $name . (in_array($name, ['Di', 'Acl', 'Event'], true) ? 'ConfigFiles' : '') . 'Test.php');
    }
    $put($rest . 'SubjectRepositoryTest.php', '<?php class SubjectRepositoryTest extends WebapiAbstract {}');
    $put($rest . 'ThingRepositoryTest.php', '<?php class ThingRepositoryTest extends WebapiAbstract {}');
    $put($graphql . 'HelloTest.php', '<?php class HelloTest extends GraphQlAbstract {}');
    $put($graphql . 'SurpriseTest.php', '<?php class SurpriseTest extends GraphQlAbstract { /* { strangeField } */ }');
    $put($rest . '_files/SubjectRepositoryTest.php');

    $contract = 'app/code/Magento/Example/Api/SubjectRepositoryInterface.php';
    $put($contract);
    $result = $select([$contract, $contract]);
    $expect($result['rest'] === [$rest . 'SubjectRepositoryTest.php'], 'Service contract selection or deduplication failed.');
    $expect($result['graphql'] === [], 'Service contracts must not select GraphQL tests.');
    $expect($result['integrity'] === [$integrity . 'DiConfigFilesTest.php'], 'PHP changes need DI integrity coverage.');
    $expect($result['module'] === ['Magento_Example'], 'DI file checks must focus on changed modules.');
    $expect($select([$contract, $integrity . 'DiConfigFilesTest.php'])['module'] === [], 'Changed DI integrity tests need full coverage.');
    $expect($select([$contract, 'lib/internal/Magento/Framework/Example.php'])['module'] === [], 'Framework changes need global DI file coverage.');

    $implementation = 'app/code/Magento/Example/Model/SubjectRepository.php';
    $put($implementation, '<?php
namespace Magento\Example\Model;
use Magento\Example\Api as Contracts;
/** This documentation implements nothing. */
class SubjectRepository implements Contracts\SubjectRepositoryInterface {}');
    $expect($select([$implementation])['rest'] === [$rest . 'SubjectRepositoryTest.php'], 'Aliased service implementation was missed.');
    $otherImplementation = 'app/code/Magento/Other/Model/SubjectRepository.php';
    $put($otherImplementation, '<?php
namespace Magento\Other\Model;
use Magento\Example\Api\SubjectRepositoryInterface;
class SubjectRepository implements SubjectRepositoryInterface {}');
    $expect($select([$otherImplementation])['rest'] === [$rest . 'SubjectRepositoryTest.php'], 'Cross-module service implementation was missed.');
    $different = 'app/code/Magento/Other/Model/Different.php';
    $put($different, '<?php
namespace Magento\Other\Model;
use Magento\Example\Api\DifferentInterface;
class Different implements DifferentInterface {}');
    $put($rest . 'ContractBehaviorTest.php', '<?php use Magento\Example\Api\DifferentInterface;');
    $expect($select([$different])['rest'] === [$rest . 'ContractBehaviorTest.php'], 'Implemented contract references must select REST coverage.');

    $resource = 'app/code/Magento/Example/Model/ResourceModel/Thing/Collection.php';
    $put($resource);
    $expect($select([$resource])['rest'] === [$rest . 'ThingRepositoryTest.php'], 'Resource collections must select their entity API tests.');
    $helper = 'app/code/Magento/Example/Model/Helper.php';
    $put($helper, '<?php use Magento\Example\Api\SubjectRepositoryInterface; class Helper {}');
    $expect($select([$helper])['rest'] === [], 'An injected service alone must not trigger REST coverage.');

    $resolver = 'app/code/Magento/ExampleGraphQl/Model/Resolver/Hello.php';
    $put($resolver);
    $result = $select([$resolver]);
    $expect($result['graphql'] === [$graphql . 'HelloTest.php'], 'Conventional resolver test was missed.');
    $expect($result['rest'] === [], 'Resolver changes must not select REST tests.');

    $unusual = 'app/code/Magento/ExampleGraphQl/Model/Resolver/Unexpected.php';
    $put($unusual);
    $schema = 'app/code/Magento/ExampleGraphQl/etc/schema.graphqls';
    $put($schema, 'type Query {
        strangeField: String @resolver(class: "Magento\\\\ExampleGraphQl\\\\Model\\\\Resolver\\\\Unexpected")
    }');
    $expect($select([$unusual])['graphql'] === [$graphql . 'SurpriseTest.php'], 'Schema field fallback failed.');
    $expect($select([$schema])['graphql'] === [$graphql . 'SurpriseTest.php'], 'Schema edits need GraphQL coverage.');

    $entry = 'app/code/Magento/ExampleGraphQl/Model/Entry.php';
    $put($entry, '<?php
namespace Magento\ExampleGraphQl\Model;
use Magento\Framework\GraphQl\Query\ResolverInterface as Contract;
class Entry implements Contract {}');
    $put($schema, 'type Query {
        strangeField: String @resolver(class: "Magento\\\\ExampleGraphQl\\\\Model\\\\Entry")
    }');
    $expect($select([$entry])['graphql'] === [$graphql . 'SurpriseTest.php'], 'ResolverInterface implementation outside Model/Resolver was missed.');
    $typeResolver = 'app/code/Magento/ExampleGraphQl/Model/Resolver/Type.php';
    $put($typeResolver);
    $put($schema, 'interface ExampleType @typeResolver(class: "Magento\\\\ExampleGraphQl\\\\Model\\\\Resolver\\\\Type") {
        id: ID!
    }');
    $put($graphql . 'FragmentTest.php', '<?php class FragmentTest extends GraphQlAbstract { /* ... on ExampleType { id } */ }');
    $expect($select([$typeResolver])['graphql'] === [$graphql . 'FragmentTest.php'], 'Type resolver coverage was missed.');
    $expect($select([$schema])['graphql'] === [$graphql . 'FragmentTest.php'], 'Type resolver schema edits need GraphQL coverage.');
    $alternateTest = 'dev/tests/api-functional/testsuite/Magento/GraphQl/ExampleGraphQl/AlternateTest.php';
    $put($alternateTest, '<?php class AlternateTest extends GraphQlAbstract {}');
    $alternate = 'app/code/Magento/ExampleGraphQl/Model/Resolver/Alternate.php';
    $put($alternate);
    $expect($select([$alternate])['graphql'] === [$alternateTest], 'GraphQl-suffixed test directories were missed.');

    $moduleTest = 'app/code/Magento/ExampleGraphQl/Test/Api/CustomTest.php';
    $put($moduleTest, '<?php class CustomTest extends \Magento\TestFramework\TestCase\GraphQlAbstract {}');
    $expect($select([$moduleTest])['graphql'] === [$moduleTest], 'Module-local GraphQL API tests were misclassified.');
    $expect($select([$rest . 'SubjectRepositoryTest.php'])['rest'] === [$rest . 'SubjectRepositoryTest.php'], 'Changed REST tests were missed.');
    $expect($select([$graphql . 'HelloTest.php'])['graphql'] === [$graphql . 'HelloTest.php'], 'Changed GraphQL tests were missed.');
    $expect($select([$rest . '_files/SubjectRepositoryTest.php'])['rest'] === [], 'API fixture files must not run as tests.');
    $expect($select(['app/code/Magento/Example/Test/Unit/HelperTest.php'])['rest'] === [], 'Unit tests leaked into API selection.');

    $result = $select(['app/code/Magento/Example/etc/acl.xml', 'app/code/Magento/Example/etc/frontend/events.xml']);
    $expect($result['integrity'] === [$integrity . 'AclConfigFilesTest.php', $integrity . 'EventConfigFilesTest.php'], 'Configuration integrity routing failed.');
    $result = $select(['app/code/Magento/Example/etc/cache.xml']);
    $expect($result['integrity'] === [$integrity . 'CacheFilesTest.php'], 'Cache configuration integrity coverage was missed.');
    $result = $select(['app/code/Magento/Example/Block/Subject.php']);
    $expect(in_array($integrity . 'BlockInstantiationTest.php', $result['integrity'], true), 'Block changes need instantiation checks.');
    $expect($result['block'] === ['Magento_Example'], 'Block integrity must focus on changed modules.');
    $result = $select(['app/code/Magento/Example/Block/Subject.php', $integrity . 'BlockInstantiationTest.php']);
    $expect($result['block'] === [], 'Changes to the native integrity test must retain its full coverage.');
    $result = $select(['app/code/Magento/Example/view/frontend/layout/example.xml']);
    $expect($result['integrity'] === [$integrity . 'LayoutFilesTest.php'], 'Layout integrity coverage was missed.');
    $result = $select(['app/code/Magento/Example/view/frontend/templates/example.phtml']);
    $expect($result['integrity'] === [$integrity . 'TemplateFilesTest.php'], 'Template integrity coverage was missed.');
    $unmapped = 'app/code/Magento/Example/Api/UnmappedInterface.php';
    $put($unmapped);
    $result = $select([$unmapped]);
    $expect($result['rest'] === [] && count($result['warning']) === 1, 'Unmapped API coverage must be reported.');

    printf("Integrity and API selection regression checks passed.\n");
} finally {
    $filesystem->remove($fixture);
}
