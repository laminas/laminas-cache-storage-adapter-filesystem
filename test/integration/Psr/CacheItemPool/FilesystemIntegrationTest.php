<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\CacheItemPool;

use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractCacheItemPoolIntegrationTest;

use function assert;
use function gc_collect_cycles;
use function getenv;
use function mkdir;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;

class FilesystemIntegrationTest extends AbstractCacheItemPoolIntegrationTest
{
    /** @var string */
    private $tmpCacheDir;

    /** @var int */
    protected $umask;

    private ?FilesystemOptions $options = null;

    protected function setUp(): void
    {
        $keyLengthMessage = 'Filesystem adapter supports only 64 characters for a cache key';

        $this->skippedTests = [
            'testBasicUsageWithLongKey' => $keyLengthMessage,
        ];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->umask !== umask()) {
            umask($this->umask);
            $this->fail('Umask was not reset');
        }

        assert($this->options instanceof FilesystemOptions);

        if ($this->options->getCacheDir() !== $this->tmpCacheDir) {
            $this->options->setCacheDir($this->tmpCacheDir);
        }

        parent::tearDown();
    }

    protected function createStorage(): StorageInterface
    {
        $this->umask = umask();

        if (getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR')) {
            $cacheDir = getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR');
        } else {
            $cacheDir = sys_get_temp_dir();
        }

        $this->tmpCacheDir = tempnam($cacheDir, 'laminas_cache_test_');

        if (! $this->tmpCacheDir) {
            $this->fail("Can't create temporary cache directory-file.");
        } elseif (! unlink($this->tmpCacheDir)) {
            $this->fail("Can't remove temporary cache directory-file: {$this->tmpCacheDir}");
        } elseif (! mkdir($this->tmpCacheDir, 0777)) {
            $this->fail("Can't create temporary cache directory.");
        }

        $this->options = new FilesystemOptions([
            'cache_dir' => $this->tmpCacheDir,
        ]);
        $storage       = new Filesystem($this->options);

        $storage->addPlugin(new Serializer());

        return $storage;
    }

    /**
     * Overwrite parent test to save cache directory when creating new cache pool
     */
    public function testSaveWithoutExpire(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $item = $this->cache->getItem('test_ttl_null');
        $item->set('data');
        $this->cache->save($item);

        $cacheDir = $this->tmpCacheDir;

        // Use a new pool instance to ensure that we don't hit any caches
        $pool = $this->createCachePool();
        $this->options->setCacheDir($cacheDir);

        $item = $pool->getItem('test_ttl_null');

        self::assertTrue($item->isHit(), 'Cache should have retrieved the items');
        self::assertEquals('data', $item->get());
    }

    /**
     * Overwrite parent test to save cache directory when creating new pool
     */
    public function testDeferredSaveWithoutCommit(): void
    {
        if (isset($this->skippedTests[__FUNCTION__])) {
            $this->markTestSkipped($this->skippedTests[__FUNCTION__]);
        }

        $this->prepareDeferredSaveWithoutCommit();
        $cacheDir = $this->tmpCacheDir;
        gc_collect_cycles();

        $cache = $this->createCachePool();
        $this->options->setCacheDir($cacheDir);

        self::assertTrue(
            $cache->getItem('key')->isHit(),
            'A deferred item should automatically be committed on CachePool::__destruct().'
        );
    }

    private function prepareDeferredSaveWithoutCommit(): void
    {
        $cache       = $this->cache;
        $this->cache = null;
        $item        = $cache->getItem('key');
        $item->set('4711');
        $cache->saveDeferred($item);
    }
}
