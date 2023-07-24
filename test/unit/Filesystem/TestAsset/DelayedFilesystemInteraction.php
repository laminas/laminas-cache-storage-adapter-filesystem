<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Filesystem\TestAsset;

use Laminas\Cache\Storage\Adapter\Filesystem\FilesystemInteractionInterface;
use Laminas\Cache\Storage\Adapter\Filesystem\LocalFilesystemInteraction;

use function usleep;

final class DelayedFilesystemInteraction implements FilesystemInteractionInterface
{
    private FilesystemInteractionInterface $filesystem;

    /** @psalm-var positive-int */
    private int $delay;

    /** @psalm-param positive-int $delay */
    public function __construct(int $delay)
    {
        $this->filesystem = new LocalFilesystemInteraction();
        $this->delay      = $delay;
    }

    public function delete(string $file): bool
    {
        usleep($this->delay);

        return $this->filesystem->delete($file);
    }

    public function write(
        string $file,
        string $contents,
        ?int $umask,
        ?int $permissions,
        bool $lock,
        bool $block,
        ?bool &$wouldBlock
    ): bool {
        return $this->filesystem->write(
            $file,
            $contents,
            $umask,
            $permissions,
            $lock,
            $block,
            $wouldBlock
        );
    }

    public function read(string $file, bool $lock, bool $block, ?bool &$wouldBlock): string
    {
        return $this->filesystem->read($file, $lock, $block, $wouldBlock);
    }

    public function expired(string $file): bool
    {
        return $this->filesystem->expired($file);
    }

    public function exists(string $file): bool
    {
        return $this->filesystem->exists($file);
    }

    public function lastModifiedTime(string $file): int
    {
        return $this->filesystem->lastModifiedTime($file);
    }

    public function lastAccessedTime(string $file): int
    {
        return $this->filesystem->lastAccessedTime($file);
    }

    public function createdTime(string $file): int
    {
        return $this->filesystem->createdTime($file);
    }

    public function filesize(string $file): int
    {
        return $this->filesystem->filesize($file);
    }

    public function clearStatCache(): void
    {
        $this->filesystem->clearStatCache();
    }

    public function availableBytes(string $directory): int
    {
        return $this->filesystem->availableBytes($directory);
    }

    public function totalBytes(string $directory): int
    {
        return $this->filesystem->totalBytes($directory);
    }

    public function touch(string $file): bool
    {
        return $this->filesystem->touch($file);
    }

    public function umask(int $umask): int
    {
        return $this->filesystem->umask($umask);
    }

    public function createDirectory(string $directory, int $permissions, bool $recursive, ?int $umask = null): void
    {
        $this->filesystem->createDirectory($directory, $permissions, $recursive, $umask);
    }
}
