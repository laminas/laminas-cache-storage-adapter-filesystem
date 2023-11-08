<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Psr\SimpleCache;

use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Cache\Storage\StorageInterface;
use LaminasTest\Cache\Storage\Adapter\AbstractSimpleCacheIntegrationTest;

use function assert;
use function getenv;
use function mkdir;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;

class FilesystemIntegrationTest extends AbstractSimpleCacheIntegrationTest
{
    /** @var string */
    private $tmpCacheDir;

    /** @var int */
    protected $umask;

    private ?FilesystemOptions $options = null;

    protected function setUp(): void
    {
        $ttlMessage       = 'Filesystem adapter does not honor TTL';
        $keyMessage       = 'Filesystem adapter supports a subset of PSR-16 characters for keys';
        $keyLengthMessage = 'Filesystem adapter supports only 64 characters for a cache key';

        $this->skippedTests = [
            'testSetTtl'                => $ttlMessage,
            'testSetMultipleTtl'        => $ttlMessage,
            'testSetValidKeys'          => $keyMessage,
            'testSetMultipleValidKeys'  => $keyMessage,
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

        $cacheDir = getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR');
        $cacheDir = is_string($cacheDir) ? $cacheDir : sys_get_temp_dir();

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
}
