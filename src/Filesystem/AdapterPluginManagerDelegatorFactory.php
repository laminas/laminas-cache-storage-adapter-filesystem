<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter\Filesystem;

use Interop\Container\ContainerInterface; // phpcs:disable WebimpressCodingStandard.PHP.CorrectClassNameCase
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\AdapterPluginManager;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Laminas\ServiceManager\ServiceManager;

use function assert;

/**
 * @psalm-import-type ServiceManagerConfiguration from ServiceManager
 */
final class AdapterPluginManagerDelegatorFactory
{
    public function __invoke(ContainerInterface $container, string $name, callable $callback): AdapterPluginManager
    {
        $pluginManager = $callback();
        assert($pluginManager instanceof AdapterPluginManager);

        /** @var ServiceManagerConfiguration $config */
        $config = [
            'factories' => [
                Filesystem::class => InvokableFactory::class,
            ],
            'aliases'   => [
                'filesystem' => Filesystem::class,
                'Filesystem' => Filesystem::class,
            ],
        ];

        $pluginManager->configure($config);

        return $pluginManager;
    }
}
