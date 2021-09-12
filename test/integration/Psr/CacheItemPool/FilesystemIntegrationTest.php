<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Laminas\Cache\Psr\CacheItemPool\CacheException;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\StorageFactory;
use PHPUnit\Framework\TestCase;

class FilesystemIntegrationTest extends TestCase
{
    public function testAdapterNotSupported()
    {
        $this->expectException(CacheException::class);
        $storage = StorageFactory::adapterFactory('filesystem');
        $storage->addPlugin(new Serializer());
        new CacheItemPoolDecorator($storage);
    }
}
