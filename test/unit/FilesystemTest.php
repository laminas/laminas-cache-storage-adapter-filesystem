<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use DirectoryIterator;
use Exception;
use Laminas\Cache;
use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Storage\Plugin\ExceptionHandler;
use Laminas\Cache\Storage\Plugin\PluginOptions;

use function chmod;
use function count;
use function error_get_last;
use function exec;
use function file_exists;
use function fileatime;
use function filectime;
use function getenv;
use function glob;
use function implode;
use function md5;
use function mkdir;
use function pcntl_fork;
use function posix_getpid;
use function posix_kill;
use function realpath;
use function rmdir;
use function sleep;
use function str_replace;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function umask;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PHP_OS;
use const SIGTERM;

final class FilesystemTest extends AbstractCommonAdapterTest
{
    /** @var string */
    protected $tmpCacheDir;

    /** @var int */
    protected $umask;

    protected function setUp(): void
    {
        $this->umask = umask();

        if (getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR')) {
            $cacheDir = getenv('TESTS_LAMINAS_CACHE_FILESYSTEM_DIR');
        } else {
            $cacheDir = sys_get_temp_dir();
        }

        $this->tmpCacheDir = @tempnam($cacheDir, 'laminas_cache_test_');
        if (! $this->tmpCacheDir) {
            $err = error_get_last();
            $this->fail("Can't create temporary cache directory-file: {$err['message']}");
        } elseif (! @unlink($this->tmpCacheDir)) {
            $err = error_get_last();
            $this->fail("Can't remove temporary cache directory-file: {$err['message']}");
        } elseif (! @mkdir($this->tmpCacheDir, 0777)) {
            $err = error_get_last();
            $this->fail("Can't create temporary cache directory: {$err['message']}");
        }

        $this->options = new Cache\Storage\Adapter\FilesystemOptions([
            'cache_dir' => $this->tmpCacheDir,
        ]);
        $this->storage = new Cache\Storage\Adapter\Filesystem();
        $this->storage->setOptions($this->options);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->_removeRecursive($this->tmpCacheDir);

        if ($this->umask !== umask()) {
            umask($this->umask);
            $this->fail("Umask wasn't reset");
        }

        parent::tearDown();
    }

    // @codingStandardsIgnoreStart
    protected function _removeRecursive($dir)
    {
        // @codingStandardsIgnoreEnd
        if (file_exists($dir)) {
            $dirIt = new DirectoryIterator($dir);
            foreach ($dirIt as $entry) {
                $fname = $entry->getFilename();
                if ($fname === '.' || $fname === '..') {
                    continue;
                }

                if ($entry->isFile()) {
                    unlink($entry->getPathname());
                } else {
                    $this->_removeRecursive($entry->getPathname());
                }
            }

            rmdir($dir);
        }
    }

    public function getCommonAdapterNamesProvider(): array
    {
        return [
            ['filesystem'],
            ['Filesystem'],
        ];
    }

    public function testNormalizeCacheDir()
    {
        $cacheDir = $cacheDirExpected = realpath(sys_get_temp_dir());

        if (DIRECTORY_SEPARATOR !== '/') {
            $cacheDir = str_replace(DIRECTORY_SEPARATOR, '/', $cacheDir);
        }

        $firstSlash = strpos($cacheDir, '/');
        $cacheDir   = substr($cacheDir, 0, $firstSlash + 1)
                  . '..//../'
                  . substr($cacheDir, $firstSlash)
                  . '///';

        $this->options->setCacheDir($cacheDir);
        $cacheDir = $this->options->getCacheDir();

        $this->assertEquals($cacheDirExpected, $cacheDir);
    }

    public function testSetCacheDirToSystemsTempDirWithNull()
    {
        $this->options->setCacheDir(null);
        $this->assertEquals(sys_get_temp_dir(), $this->options->getCacheDir());
    }

    public function testSetCacheDirNoDirectoryException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setCacheDir(__FILE__);
    }

    public function testSetCacheDirNotWritableException()
    {
        if (substr(PHP_OS, 0, 3) === 'WIN') {
            $this->markTestSkipped("Not testable on windows");
        } else {
            @exec('whoami 2>&1', $out, $ret);
            if ($ret) {
                $err = error_get_last();
                $this->markTestSkipped("Not testable: {$err['message']}");
            } elseif (isset($out[0]) && $out[0] === 'root') {
                $this->markTestSkipped("Not testable as root");
            }
        }

        $this->expectException(InvalidArgumentException::class);

        // create a not writable temporaty directory
        $testDir = tempnam(sys_get_temp_dir(), 'LaminasTest');
        unlink($testDir);
        mkdir($testDir);
        chmod($testDir, 0557);

        try {
            $this->options->setCacheDir($testDir);
        } catch (Exception $e) {
            rmdir($testDir);
            throw $e;
        }
    }

    public function testSetCacheDirNotReadableException()
    {
        if (substr(PHP_OS, 0, 3) === 'WIN') {
            $this->markTestSkipped("Not testable on windows");
        } else {
            @exec('whoami 2>&1', $out, $ret);
            if ($ret) {
                $this->markTestSkipped("Not testable: " . implode("\n", $out));
            } elseif (isset($out[0]) && $out[0] === 'root') {
                $this->markTestSkipped("Not testable as root");
            }
        }

        $this->expectException(InvalidArgumentException::class);

        // create a not readable temporaty directory
        $testDir = tempnam(sys_get_temp_dir(), 'LaminasTest');
        unlink($testDir);
        mkdir($testDir);
        chmod($testDir, 0337);

        try {
            $this->options->setCacheDir($testDir);
        } catch (Exception $e) {
            rmdir($testDir);
            throw $e;
        }
    }

    public function testSetFilePermissionThrowsExceptionIfNotWritable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0466);
    }

    public function testSetFilePermissionThrowsExceptionIfNotReadable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0266);
    }

    public function testSetFilePermissionThrowsExceptionIfExecutable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setFilePermission(0661);
    }

    public function testSetNoAtimeChangesAtimeOfMetadataCapability()
    {
        $capabilities = $this->storage->getCapabilities();

        $this->options->setNoAtime(false);
        $this->assertContains('atime', $capabilities->getSupportedMetadata());

        $this->options->setNoAtime(true);
        $this->assertNotContains('atime', $capabilities->getSupportedMetadata());
    }

    public function testSetNoCtimeChangesCtimeOfMetadataCapability()
    {
        $capabilities = $this->storage->getCapabilities();

        $this->options->setNoCtime(false);
        $this->assertContains('ctime', $capabilities->getSupportedMetadata());

        $this->options->setNoCtime(true);
        $this->assertNotContains('ctime', $capabilities->getSupportedMetadata());
    }

    public function testSetDirPermissionThrowsExceptionIfNotWritable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0577);
    }

    public function testSetDirPermissionThrowsExceptionIfNotReadable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0377);
    }

    public function testSetDirPermissionThrowsExceptionIfNotExecutable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirPermission(0677);
    }

    public function testSetDirLevelInvalidException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setDirLevel(17); // must between 0-16
    }

    public function testSetUmask()
    {
        $this->options->setUmask(023);
        $this->assertSame(021, $this->options->getUmask());

        $this->options->setUmask(false);
        $this->assertFalse($this->options->getUmask());
    }

    public function testSetUmaskThrowsExceptionIfNotWritable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0300);
    }

    public function testSetUmaskThrowsExceptionIfNotReadable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0200);
    }

    public function testSetUmaskThrowsExceptionIfNotExecutable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setUmask(0100);
    }

    public function testGetMetadataWithCtime()
    {
        $this->options->setNoCtime(false);

        $this->assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        $this->assertIsArray($meta);

        $expectedCtime = filectime($meta['filespec'] . '.dat');
        $this->assertEquals($expectedCtime, $meta['ctime']);
    }

    public function testGetMetadataWithAtime()
    {
        $this->options->setNoAtime(false);

        $this->assertTrue($this->storage->setItem('test', 'v'));

        $meta = $this->storage->getMetadata('test');
        $this->assertIsArray($meta);

        $expectedAtime = fileatime($meta['filespec'] . '.dat');
        $this->assertEquals($expectedAtime, $meta['atime']);
    }

    public function testClearExpiredExceptionTriggersEvent()
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

    public function testClearByNamespaceWithUnexpectedDirectory()
    {
        // create cache items at 2 different directory levels
        $this->storage->getOptions()->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->storage->getOptions()->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $this->storage->clearByNamespace($this->storage->getOptions()->getNamespace());
    }

    public function testClearByPrefixWithUnexpectedDirectory()
    {
        // create cache items at 2 different directory levels
        $this->storage->getOptions()->setDirLevel(2);
        $this->storage->setItem('a_key', 'a_value');
        $this->storage->getOptions()->setDirLevel(1);
        $this->storage->setItem('b_key', 'b_value');
        $glob = glob($this->tmpCacheDir . '/*');
        //contrived prefix which will collide with an existing directory
        $prefix = substr(md5('a_key'), 2, 2);
        $this->storage->clearByPrefix($prefix);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearByTags()
    {
        // create cache items
        $this->storage->getOptions()->setDirLevel(0);
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
            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            // delay unlink() by global variable $unlinkDelay
            $GLOBALS['unlinkDelay'] = 5000;

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
    public function testRaceConditionInClearByNamespace()
    {
        // create cache items
        $this->storage->getOptions()->setDirLevel(0);
        $this->storage->getOptions()->setNamespace('ns-other');
        $this->storage->setItems([
            'other' => 'other',
        ]);
        $this->storage->getOptions()->setNamespace('ns-4-clear');
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            // delay unlink() by global variable $unlinkDelay
            $GLOBALS['unlinkDelay'] = 5000;

            $this->storage->getOptions()->setNamespace('ns-4-clear');
            $this->storage->clearByNamespace('ns-4-clear');

            $this->assertFalse($this->storage->hasItem('a_key'));
            $this->assertFalse($this->storage->hasItem('b_key'));

            $this->storage->getOptions()->setNamespace('ns-other');
            $this->assertFalse($this->storage->hasItem('other'), 'Child process does not run as expected');
        } else {
            // The child process:
            // Wait to make sure the parent process has started determining files to unlink.
            // Than remove one of the items the parent process should remove and another item for testing.
            usleep(1000);

            $this->storage->getOptions()->setNamespace('ns-4-clear');
            $this->assertTrue($this->storage->removeItem('b_key'));

            $this->storage->getOptions()->setNamespace('ns-other');
            $this->assertTrue($this->storage->removeItem('other'));

            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testRaceConditionInClearByPrefix()
    {
        // create cache items
        $this->storage->getOptions()->setDirLevel(0);
        $this->storage->getOptions()->setNamespace('ns');
        $this->storage->setItems([
            'prefix_a_key' => 'a_value',
            'prefix_b_key' => 'b_value',
            'other'        => 'other',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            // delay unlink() by global variable $unlinkDelay
            $GLOBALS['unlinkDelay'] = 5000;

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
    public function testRaceConditionInClearExpired()
    {
        // create cache items
        $this->storage->getOptions()->setDirLevel(0);
        $this->storage->getOptions()->setTtl(2);
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
            'other' => 'other',
        ]);

        // wait TTL seconds and touch item other so this item will not be deleted by clearExpired
        // and can be used for testing the child process
        $this->waitForFullSecond();
        sleep(2);
        $this->storage->touchItem('other');

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            // The parent process
            // Slow down unlink function and start removing items.
            // Finally test if the item not matching the tag was removed by the child process.

            // delay unlink() by global variable $unlinkDelay
            $GLOBALS['unlinkDelay'] = 5000;

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
    public function testRaceConditionInFlush()
    {
        // create cache items
        $this->storage->getOptions()->setDirLevel(0);
        $this->storage->setItems([
            'a_key' => 'a_value',
            'b_key' => 'b_value',
        ]);

        $pidChild = pcntl_fork();
        if ($pidChild === -1) {
            $this->fail('pcntl_fork() failed');
        } elseif ($pidChild) {
            // The parent process
            // Slow down unlink function and start removing items.

            // delay unlink() by global variable $unlinkDelay
            $GLOBALS['unlinkDelay'] = 5000;

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

    public function testSuffixIsMutable()
    {
        $this->options->setSuffix('.cache');
        $this->assertSame('.cache', $this->options->getSuffix());
    }

    public function testTagSuffixIsMutable()
    {
        $this->options->setTagSuffix('.cache');
        $this->assertSame('.cache', $this->options->getTagSuffix());
    }

    public function testEmptyTagsArrayClearsTags()
    {
        $key  = 'key';
        $tags = ['tag1', 'tag2', 'tag3'];
        $this->assertTrue($this->storage->setItem($key, 100));
        $this->assertTrue($this->storage->setTags($key, $tags));
        $this->assertNotEmpty($this->storage->getTags($key));
        $this->assertTrue($this->storage->setTags($key, []));
        $this->assertEmpty($this->storage->getTags($key));
    }
}
