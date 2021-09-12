<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Laminas\Cache\Psr\CacheItemPool\CacheException;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Plugin\Serializer;
use PHPUnit\Framework\TestCase;

class FilesystemIntegrationTest extends TestCase
{
    public function testAdapterNotSupported(): void
    {
        $this->expectException(CacheException::class);
        $storage = new Filesystem();
        $storage->addPlugin(new Serializer());
        new CacheItemPoolDecorator($storage);
    }
}
