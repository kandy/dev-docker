<?php

declare(strict_types=1);

namespace Mdev\Fast;

use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Utility\Files;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\View\Element\Template;
use Magento\TestFramework\Helper\Bootstrap;

require_once '/magento/dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/BlockInstantiationTest.php';

/**
 * @magentoAppIsolation enabled
 */
class BlockInstantiationTest extends \Magento\Test\Integrity\Modular\BlockInstantiationTest
{
    public function allBlocksDataProvider()
    {
        $modules = getenv('MDEV_TEST_BLOCK_MODULES');
        if ($modules === false || $modules === '') {
            throw new \RuntimeException('Changed modules are required for focused block integrity.');
        }
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore()->setWebsiteId(0);
        $objectManager->get(AreaList::class)->getArea('adminhtml')->load(Area::PART_CONFIG);
        $registrar = new ComponentRegistrar();
        $enabled = $this->_getEnabledModules();
        $skip = $this->_getBlocksToSkip();
        $cases = [];
        foreach (array_unique(explode(',', $modules)) as $module) {
            $path = $registrar->getPath(ComponentRegistrar::MODULE, $module);
            if ($path === null) {
                throw new \RuntimeException("Unknown module for focused block integrity: $module.");
            }
            if (!isset($enabled[$module]) || !is_dir("$path/Block")) {
                continue;
            }
            foreach (Files::getFiles(["$path/Block"], '*.php') as $file) {
                $class = str_replace('_', '\\', $module) . '\\'
                    . str_replace('/', '\\', substr($file, strlen($path) + 1, -4));
                if (isset($skip[$class])) {
                    continue;
                }
                $reflection = new \ReflectionClass($class);
                if ($reflection->isAbstract() || !$reflection->isSubclassOf(Template::class)) {
                    continue;
                }
                $admin = $module === 'Magento_Backend' || str_contains($class, '\\Adminhtml\\')
                    || str_contains($class, '_Backend_')
                    || $reflection->isSubclassOf(\Magento\Backend\Block\Template::class);
                $area = $admin ? 'adminhtml' : 'frontend';
                $cases["$module, $class, $area"] = [$module, $class, $area];
            }
        }
        if ($cases === []) {
            $this->markTestSkipped('No enabled template blocks in the changed modules.');
        }
        return $cases;
    }
}
