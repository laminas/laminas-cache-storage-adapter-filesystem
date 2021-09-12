<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Composer;

use Laminas\Cache\Storage\Adapter\Filesystem\ConfigProvider;
use LaminasTest\Cache\Storage\Adapter\LaminasComponentInstallerIntegrationTestTrait;
use LaminasTest\Cache\Storage\Adapter\LaminasConfigAggregatorIntegrationTestTrait;
use LaminasTest\Cache\Storage\Adapter\LaminasModuleManagerModuleIntegrationTestTrait;
use PHPUnit\Framework\TestCase;

final class LaminasComponentInstallerIntegrationTest extends TestCase
{
    use LaminasComponentInstallerIntegrationTestTrait;
    use LaminasConfigAggregatorIntegrationTestTrait;
    use LaminasModuleManagerModuleIntegrationTestTrait;

    protected function getComposerJsonPath(): string
    {
        return __DIR__ . '/../../../composer.json';
    }

    protected function getConfigProviderClassName(): string
    {
        return ConfigProvider::class;
    }
}
