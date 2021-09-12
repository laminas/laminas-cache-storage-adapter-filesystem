<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Filesystem;

use Laminas\Cache\Storage\Adapter\Filesystem\ConfigProvider;
use Laminas\Cache\Storage\Adapter\Filesystem\Module;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    /** @var Module */
    private $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module();
    }

    public function testWillReturnConfigProviderConfiguration(): void
    {
        $expected = (new ConfigProvider())->getServiceDependencies();
        $config   = $this->module->getConfig();
        self::assertArrayHasKey('service_manager', $config);
        self::assertSame($expected, $config['service_manager']);
        self::assertArrayNotHasKey('dependencies', $config);
    }
}
