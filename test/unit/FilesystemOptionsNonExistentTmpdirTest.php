<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use Laminas\Cache\Storage\Adapter\FilesystemOptions;
use PHPUnit\Framework\TestCase;

use function is_writable;
use function sys_get_temp_dir;

final class FilesystemOptionsNonExistentTmpdirTest extends TestCase
{
    public function testWillNotWriteToSystemTempWhenCacheDirIsProvided(): void
    {
        if (is_writable(sys_get_temp_dir())) {
            self::markTestSkipped('Test has to be executed with a non existent TMPDIR');
        }

        $option = new FilesystemOptions(['cacheDir' => '/./tmp']);
        self::assertSame('/tmp', $option->getCacheDir());
    }
}
