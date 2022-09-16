<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Filesystem;

use Laminas\Cache\Storage\Adapter\Filesystem\AdapterPluginManagerDelegatorFactory;
use Laminas\Cache\Storage\Adapter\Filesystem\ConfigProvider;
use Laminas\Cache\Storage\AdapterPluginManager;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ConfigProvider();
    }

    public function testWillProvideAdapterPluginManagerDelegatorFactory(): void
    {
        $dependencies               = $this->provider->getServiceDependencies();
        $delegatorFromConfiguration = $dependencies['delegators'] ?? [];
        self::assertIsArray($delegatorFromConfiguration);
        $delegatorsForPluginManager = $delegatorFromConfiguration[AdapterPluginManager::class] ?? [];
        self::assertIsArray($delegatorsForPluginManager);
        self::assertContains(AdapterPluginManagerDelegatorFactory::class, $delegatorsForPluginManager);
    }
}
