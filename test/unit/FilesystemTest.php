<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use Laminas\Cache\Storage\Plugin\ExceptionHandler;
use Laminas\Cache\Storage\Plugin\PluginOptions;
use LaminasTest\Cache\Storage\Adapter\Filesystem\TestAsset\DelayedFilesystemInteraction;

use function chmod;
use function count;
use function error_get_last;
use function fileatime;
use function filectime;
use function getenv;
use function glob;
use function md5;
use function mkdir;
use function pcntl_fork;
use function posix_getpid;
use function posix_kill;
use function sleep;
use function sort;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;
use function usleep;

use const SIGTERM;

/**
 * @template-extends AbstractCommonAdapterTest<Filesystem,FilesystemOptions>
 */
final class FilesystemTest extends AbstractCommonAdapterTest
{
    protected string $tmpCacheDir;

    protected int $umask;

    protected function setUp(): void
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
        } elseif (! @unlink($this->tmpCacheDir)) {
            $err = error_get_last();
            $this->fail("Can't remove temporary cache directory-file: {$err['message']}");
        } elseif (! @mkdir($this->tmpCacheDir, 0777)) {
            $err = error_get_last();
            $this->fail("Can't create temporary cache directory: {$err['message']}");
        }

        $this->options = new FilesystemOptions([
            'cache_dir' => $this->tmpCacheDir,
        ]);
        $this->storage = new Filesystem();
        $this->storage->setOptions($this->options);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->umask !== umask()) {
            umask($this->umask);
            $this->fail("Umask wasn't reset");
        }

        if ($this->options->getCacheDir() !== $this->tmpCacheDir) {
            $this->options->setCacheDir($this->tmpCacheDir);
        }

        parent::tearDown();
    }

    public function testFileSystemeOptionIsUpdatedWhenFileSystemeOptionIsChange(): void
    {
        $storage = new Filesystem();
        $options = new FilesystemOptions();
        $storage->setOptions($options);
        $options->setCacheDir($this->tmpCacheDir);

        $this->assertSame($this->tmpCacheDir, $storage->getOptions()->getCacheDir());
    }

    public function testSetNoAtimeChangesAtimeOfMetadataCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $this->options->setNoAtime(false);
        $this->assertContains('atime', $capabilities->getSupportedMetadata());

        $this->options->setNoAtime(true);
        $this->assertNotContains('atime', $capabilities->getSupportedMetadata());
    }

    public function testSetNoCtimeChangesCtimeOfMetadataCapability(): void
    {
        $capabilities = $this->storage->getCapabilities();

        $this->options->setNoCtime(false);
        $this->assertContains('ctime', $capabilities->getSupportedMetadata());

        $this->options->setNoCtime(true);
        $this->assertNotContains('ctime', $capabilities->getSupportedMetadata());
    }

    public function testGetMetadataWithCtime(): void
    {
        $this->options->setNoCtime(false);

        $this->assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        $this->assertIsArray($meta);

        $expectedCtime = filectime($meta['filespec'] . '.dat');
        $this->assertEquals($expectedCtime, $meta['ctime']);
    }

    public function testGetMetadataWithAtime(): void
    {
        $this->options->setNoAtime(false);

        $this->assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        $this->assertIsArray($meta);

        $expectedAtime = fileatime($meta['filespec'] . '.dat');
        $this->assertEquals($expectedAtime, $meta['atime']);
    }

    public function testClearExpiredExceptionTriggersEvent(): void
    {
        $this->options->setTtl(0.1);
        $this->storage->setItem('k', 'v');
        $dirs = glob($this->tmpCacheDir . '/*');
        if (count($dirs) === 0) {
            $this->fail('Could not find cache dir');
        }
        chmod($dirs[0], 0500); //make directory rx, unlink should fail
        sleep(1); //wait for the entry to expire
        $plugin  = new ExceptionHandler();
        $options = new PluginOptions(['throw_exceptions' => false]);
        $plugin->setOptions($options);
        $this->storage->addPlugin($plugin);
        $this->storage->clearExpired();
        chmod($dirs[0], 0700); //set dir back to writable for tearDown
    }

    public function testClearByNamespaceWithUnexpectedDirectory(): void
    {
        // create cache items at 2 different directory levels
        $this->options->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->options->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $this->storage->clearByNamespace($this->options->getNamespace());
    }

    public function testClearByPrefixWithUnexpectedDirectory(): void
    {
        // create cache items at 2 different directory levels
        $this->options->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->options->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $glob = glob($this->tmpCacheDir . '/*');
        //contrived prefix which will collide with an existing directory
        $prefix = substr(md5('a_key'), 2, 2);
        $this->storage->clearByPrefix($prefix);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearByTags(): void
    {
        // create cache items
        $this->options->setDirLevel(0);
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
            'other' => 'other',
        ]);
        $this->storage->setTags('a_key', ['a_tag']);
        $this->storage->setTags('b_key', ['a_tag']);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            $this->storage = new Filesystem($this->options, new DelayedFilesystemInteraction(5000));
            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            $this->storage->clearByTags(['a_tag'], true);
            $this->assertFalse($this->storage->hasItem('other'), 'Child process does not run as expected');
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove and another item for testing.
            usleep(1000);
            $this->storage->removeItems(['b_key', 'other']);
            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearByNamespace(): void
    {
        // create cache items
        $this->options->setDirLevel(0);
        $this->options->setNamespace('ns-other');
        $this->storage->setItems([
            'other' => 'other',
        ]);
        $this->options->setNamespace('ns-4-clear');
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            $this->storage = new Filesystem($this->options, new DelayedFilesystemInteraction(5000));

            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            $this->options->setNamespace('ns-4-clear');
            $this->storage->clearByNamespace('ns-4-clear');

            $this->assertFalse($this->storage->hasItem('a_key'));
            $this->assertFalse($this->storage->hasItem('b_key'));

            $this->options->setNamespace('ns-other');
            $this->assertFalse($this->storage->hasItem('other'), 'Child process does not run as expected');
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove and another item for testing.
            usleep(1000);

            $this->options->setNamespace('ns-4-clear');
            $this->assertTrue($this->storage->removeItem('b_key'));

            $this->options->setNamespace('ns-other');
            $this->assertTrue($this->storage->removeItem('other'));

            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearByPrefix(): void
    {
        // create cache items
        $this->options->setDirLevel(0);
        $this->options->setNamespace('ns');
        $this->storage->setItems([
            'prefix_a_key' => 'a_value',
            'prefix_b_key' => 'b_value',
            'other'        => 'other',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            $this->storage = new Filesystem($this->options, new DelayedFilesystemInteraction(5000));

            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            $this->storage->clearByPrefix('prefix_');

            $this->assertFalse($this->storage->hasItem('prefix_a_key'));
            $this->assertFalse($this->storage->hasItem('prefix_b_key'));

            $this->assertFalse($this->storage->hasItem('other'), 'Child process does not run as expected');
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove and another item for testing.
            usleep(1000);

            $this->assertTrue($this->storage->removeItem('prefix_b_key'));
            $this->assertTrue($this->storage->removeItem('other'));

            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearExpired(): void
    {
        // create cache items
        $this->options->setDirLevel(0);
        $this->options->setTtl(2);
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
        ]);
        //Set other item with a higher ttl
        $this->options->setTtl(5);
        $this->storage->setItem('other', 'other');

        // wait TTL seconds for the first 2 items to expire. Item other will not be deleted by clearExpired
        // and can be used for testing the child process
        $this->waitForFullSecond();
        sleep(2);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            $this->storage = new Filesystem($this->options, new DelayedFilesystemInteraction(5000));

            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            $this->storage->clearExpired();

            $this->assertFalse($this->storage->hasItem('a_key'));
            $this->assertFalse($this->storage->hasItem('b_key'));

            $this->assertFalse($this->storage->hasItem('other'), 'Child process does not run as expected');
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove and another item for testing.
            usleep(1000);

            $this->assertTrue($this->storage->removeItem('b_key'));
            $this->assertTrue($this->storage->removeItem('other'));

            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInFlush(): void
    {
        // create cache items
        $this->options->setDirLevel(0);
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            $this->storage = new Filesystem($this->options, new DelayedFilesystemInteraction(5000));

            // The parent process
            // Slow down unlink function and start removing items.

            $this->storage->flush();

            $this->assertFalse($this->storage->hasItem('a_key'));
            $this->assertFalse($this->storage->hasItem('b_key'));
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove.
            usleep(1000);

            $this->assertTrue($this->storage->removeItem('b_key'));

            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    public function testEmptyTagsArrayClearsTags(): void
    {
        $key  = 'key';
        $tags = ['tag1', 'tag2', 'tag3'];
        $this->assertTrue($this->storage->setItem($key, 100));
        $this->assertTrue($this->storage->setTags($key, $tags));
        $this->assertNotEmpty($this->storage->getTags($key));
        $this->assertTrue($this->storage->setTags($key, []));
        $this->assertEmpty($this->storage->getTags($key));
    }

    public function testWillThrowRuntimeExceptionIfNamespaceIsTooLong(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid maximum key length was calculated.');

        $options = new FilesystemOptions([
            'namespace'           => str_repeat('a', 249),
            'namespace_separator' => '::',
        ]);

        $storage = new Filesystem($options);
        $storage->getCapabilities();
    }

    public function testFileDeletedWhenExpired(): void
    {
        $this->options->setTtl(1);
        $this->storage->setItems([
            'key1' => 1,
            'key2' => 2,
            'key3' => 3,
        ]);
        $this->options->setTtl(4);
        $this->storage->setItem('key4', 4);

        $expectedResult = ['key1', 'key2', 'key3', 'key4'];
        $result         = $this->storage->hasItems(['key1', 'key2', 'key3', 'key4']);
        sort($result);
        $this->assertEquals($expectedResult, $result);
        //wait for cache to expire
        sleep(2);
        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertFalse($this->storage->removeItem('key1'));
        $this->assertNull($this->storage->getItem('key2'));
        $this->assertFalse($this->storage->removeItem('key2'));
        $this->assertEquals(['key4' => 4], $this->storage->getItems(['key3', 'key4']));
        $this->assertFalse($this->storage->removeItem('key3'));
        $this->assertTrue($this->storage->removeItem('key4'));
    }

    public function testFileNotDeletedWhenExpiredStillInvalidTriggersEventWithoutThrowing(): void
    {
        $this->options->setTtl(2);
        $this->storage->setItems([
            'key1' => 1,
            'key2' => 2,
            'key3' => 3,
        ]);
        $this->options->setTtl(4);
        $this->storage->setItem('key4', 4);

        $expectedResult = ['key1', 'key2', 'key3', 'key4'];
        $result         = $this->storage->hasItems(['key1', 'key2', 'key3', 'key4']);
        sort($result);
        $this->assertEquals($expectedResult, $result);

        $dirs = glob($this->tmpCacheDir . '/*');

        if (count($dirs) === 0) {
            $this->fail('Could not find cache dir');
        }

        foreach ($dirs as $dir) {
            chmod($dir, 0500); //make directory rx, unlink should fail
        }
        //wait for cache to expire
        sleep(2);

        $this->assertFalse($this->storage->hasItem('key1'));
        $this->assertNull($this->storage->getItem('key2'));
        $this->assertEquals(['key4' => 4], $this->storage->getItems(['key3', 'key4']));
        foreach ($dirs as $dir) {
            chmod($dir, 0700); //set dir back to writable for tearDown
        }
    }
}
