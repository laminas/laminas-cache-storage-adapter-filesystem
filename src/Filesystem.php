<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use ArrayObject;
use ErrorException;
use Exception as BaseException;
use GlobIterator;
use Laminas\Cache\Exception;
use Laminas\Cache\Exception\ExceptionInterface;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\MetadataException;
use Laminas\Cache\Storage\Adapter\Filesystem\Exception\UnlinkException;
use Laminas\Cache\Storage\Adapter\Filesystem\FilesystemInteractionInterface;
use Laminas\Cache\Storage\Adapter\Filesystem\LocalFilesystemInteraction;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\ClearExpiredInterface;
use Laminas\Cache\Storage\ExceptionEvent;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\OptimizableInterface;
use Laminas\Cache\Storage\TaggableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use Laminas\Stdlib\ErrorHandler;
use stdClass;
use Traversable;

use function array_diff;
use function array_search;
use function array_unshift;
use function assert;
use function basename;
use function count;
use function dirname;
use function explode;
use function func_num_args;
use function glob;
use function implode;
use function is_string;
use function max;
use function md5;
use function preg_match;
use function preg_replace;
use function rmdir;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;
use function time;

use const DIRECTORY_SEPARATOR;
use const GLOB_NOESCAPE;
use const GLOB_NOSORT;
use const GLOB_ONLYDIR;

final class Filesystem extends AbstractAdapter implements
    AvailableSpaceCapableInterface,
    ClearByNamespaceInterface,
    ClearByPrefixInterface,
    ClearExpiredInterface,
    FlushableInterface,
    IterableInterface,
    OptimizableInterface,
    TaggableInterface,
    TotalSpaceCapableInterface
{
    public const METADATA_ATIME    = 'atime';
    public const METADATA_CTIME    = 'ctime';
    public const METADATA_MTIME    = 'mtime';
    public const METADATA_FILESIZE = 'filesize';
    public const METADATA_FILESPEC = 'filespec';

    /**
     * Buffered total space in bytes
     *
     * @var null|int|float
     */
    private $totalSpace;

    /**
     * An identity for the last filespec
     * (cache directory + namespace prefix + key + directory level)
     */
    private string $lastFileSpecId = '';

    /**
     * The last used filespec
     */
    private string $lastFileSpec = '';

    private FilesystemInteractionInterface $filesystem;

    /**
     * @param  null|array|Traversable|AdapterOptions $options
     * @throws Exception\ExceptionInterface
     */
    public function __construct($options = null, ?FilesystemInteractionInterface $filesystem = null)
    {
        parent::__construct($options);
        $this->filesystem = $filesystem ?? new LocalFilesystemInteraction();

        // clean total space buffer on change cache_dir
        $events     = $this->getEventManager();
        $handle     = static function (): void {
        };
        $totalSpace = &$this->totalSpace;
        $callback   = static function ($event) use (&$events, &$handle, &$totalSpace): void {
            $params = $event->getParams();
            if (isset($params['cache_dir'])) {
                $totalSpace = null;
                $events->detach($handle);
            }
        };

        $events->attach('option', $callback);
    }

    /**
     * Set options.
     *
     * @see    Filesystem::getOptions()
     *
     * @param array|Traversable|FilesystemOptions $options
     */
    public function setOptions($options): Filesystem
    {
        if (! $options instanceof FilesystemOptions) {
            $options = new FilesystemOptions($options);
        }

        return parent::setOptions($options);
    }

    /**
     * Get options.
     *
     * @see Filesystem::setOptions()
     */
    public function getOptions(): FilesystemOptions
    {
        if (! $this->options) {
            $this->setOptions(new FilesystemOptions());
        }
        return $this->options;
    }

    /* FlushableInterface */

    /**
     * Flush the whole storage
     *
     * @throws Exception\RuntimeException
     */
    public function flush(): bool
    {
        $flags       = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $dir         = $this->getOptions()->getCacheDir();
        $clearFolder = null;
        $clearFolder = function ($dir) use (&$clearFolder, $flags): void {
            $it = new GlobIterator($dir . DIRECTORY_SEPARATOR . '*', $flags);
            foreach ($it as $pathname) {
                if ($it->isDir()) {
                    $clearFolder($pathname);
                    rmdir($pathname);
                } else {
                    // remove the file by ignoring errors if the file doesn't exist afterwards
                    // to fix a possible  condition if another process removed the file already.
                    try {
                        $this->filesystem->delete($pathname);
                    } catch (UnlinkException $exception) {
                        if ($this->filesystem->exists($pathname)) {
                            ErrorHandler::addError(
                                $exception->getErrorSeverity(),
                                $exception->getErrorMessage(),
                                $exception->getErrorFile(),
                                $exception->getErrorLine()
                            );
                        }
                    }
                }
            }
        };

        ErrorHandler::start();
        $clearFolder($dir);
        $error = ErrorHandler::stop();
        if ($error) {
            throw new Exception\RuntimeException("Flushing directory '{$dir}' failed", 0, $error);
        }

        return true;
    }

    /* ClearExpiredInterface */

    /**
     * Remove expired items
     *
     * @triggers clearExpired.exception(ExceptionEvent)
     * @throws ExceptionInterface
     * @throws ErrorException
     */
    public function clearExpired(): bool
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getSuffix());
        $glob  = new GlobIterator($path, $flags);

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            assert(is_string($pathname));

            if (! $this->filesystem->exists($pathname)) {
                continue;
            }

            $expired = false;

            //File will be removed if expired but ignore errors if the file doesn't exist afterwards
            //to fix a possible condition if another process remove the file already
            try {
                $expired = $this->filesystem->expired($pathname);
            } catch (UnlinkException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }

            if ($expired) {
                try {
                    $this->filesystem->delete($this->formatTagFilename($pathname));
                } catch (UnlinkException $exception) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }
        }

        $error = ErrorHandler::stop();

        if ($error) {
            $result = false;
            return $this->triggerException(
                __FUNCTION__,
                new ArrayObject(),
                $result,
                new Exception\RuntimeException('Failed to clear expired items', 0, $error)
            );
        }

        return true;
    }

    /* ClearByNamespaceInterface */

    /**
     * Remove items by given namespace
     *
     * @param string $namespace
     * @throws ErrorException
     * @throws ExceptionInterface
     */
    public function clearByNamespace($namespace): bool
    {
        $namespace = (string) $namespace;
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('No namespace given');
        }

        $options = $this->getOptions();
        $prefix  = $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix . '*.*';
        $glob  = new GlobIterator($path, $flags);

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            // remove the file by ignoring errors if the file doesn't exist afterwards
            // to fix a possible race condition if another process removed the file already.
            try {
                $this->filesystem->delete($pathname);
            } catch (UnlinkException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }
        }

        $err = ErrorHandler::stop();
        if ($err) {
            $result = false;
            return $this->triggerException(
                __FUNCTION__,
                new ArrayObject(),
                $result,
                new Exception\RuntimeException("Failed to clear items of namespace '{$namespace}'", 0, $err)
            );
        }

        return true;
    }

    /* ClearByPrefixInterface */

    /**
     * Remove items matching given prefix
     *
     * @param string $prefix
     * @throws Exception\RuntimeException
     */
    public function clearByPrefix($prefix): bool
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $nsPrefix  = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $nsPrefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $nsPrefix . $prefix . '*.*';
        $glob  = new GlobIterator($path, $flags);

        ErrorHandler::start();
        foreach ($glob as $pathname) {
            assert(is_string($pathname));
            // remove the file by ignoring errors if the file doesn't exist afterwards
            // to fix a possible race condition if another process removed the file already.
            try {
                $this->filesystem->delete($pathname);
            } catch (UnlinkException $exception) {
                if ($this->filesystem->exists($pathname)) {
                    ErrorHandler::addError(
                        $exception->getErrorSeverity(),
                        $exception->getErrorMessage(),
                        $exception->getErrorFile(),
                        $exception->getErrorLine()
                    );
                }
            }
        }
        $err = ErrorHandler::stop();
        if ($err) {
            $result = false;
            return $this->triggerException(
                __FUNCTION__,
                new ArrayObject(),
                $result,
                new Exception\RuntimeException("Failed to remove files of '{$path}'", 0, $err)
            );
        }

        return true;
    }

    /* TaggableInterface  */

    /**
     * Set tags to an item by given key.
     * An empty array will remove all tags.
     *
     * @param string   $key
     * @param string[] $tags
     */
    public function setTags($key, array $tags): bool
    {
        $this->normalizeKey($key);
        if (! $this->internalHasItem($key)) {
            return false;
        }

        $filespec = $this->getFileSpec($key);

        if (! $tags) {
            $this->filesystem->delete($this->formatTagFilename($filespec));
            return true;
        }

        $this->putFileContent(
            $this->formatTagFilename($filespec),
            implode("\n", $tags)
        );
        return true;
    }

    /**
     * Get tags of an item by given key
     *
     * @param string $key
     * @return string[]|FALSE
     */
    public function getTags($key)
    {
        $this->normalizeKey($key);
        if (! $this->internalHasItem($key)) {
            return false;
        }

        $filespec = $this->formatTagFilename($this->getFileSpec($key));
        $tags     = [];
        if ($this->filesystem->exists($filespec)) {
            $tags = explode("\n", $this->getFileContent($filespec));
        }

        return $tags;
    }

    /**
     * Remove items matching given tags.
     *
     * If $disjunction only one of the given tags must match
     * else all given tags must match.
     *
     * @param string[] $tags
     * @param  bool  $disjunction
     */
    public function clearByTags(array $tags, $disjunction = false): bool
    {
        if (! $tags) {
            return true;
        }

        $tagCount  = count($tags);
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

        $flags = GlobIterator::SKIP_DOTS | GlobIterator::CURRENT_AS_PATHNAME;
        $path  = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getTagSuffix());
        $glob  = new GlobIterator($path, $flags);

        foreach ($glob as $pathname) {
            assert(is_string($pathname));
            try {
                $diff = array_diff($tags, explode("\n", $this->getFileContent($pathname)));
            } catch (Exception\RuntimeException $exception) {
                // ignore missing files because of possible raise conditions
                // e.g. another process already deleted that item
                if (! $this->filesystem->exists($pathname)) {
                    continue;
                }
                throw $exception;
            }

            $rem = false;
            if ($disjunction && count($diff) < $tagCount) {
                $rem = true;
            } elseif (! $disjunction && ! $diff) {
                $rem = true;
            }

            if ($rem) {
                $this->filesystem->delete($pathname);

                $datPathname = $this->formatFilename(substr($pathname, 0, -4));
                if ($this->filesystem->exists($datPathname)) {
                    $this->filesystem->delete($datPathname);
                }
            }
        }

        return true;
    }

    /* IterableInterface */

    /**
     * Get the storage iterator
     *
     * @return FilesystemIterator
     */
    public function getIterator(): Traversable
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();
        $path      = $options->getCacheDir()
            . str_repeat(DIRECTORY_SEPARATOR . $prefix . '*', $options->getDirLevel())
            . DIRECTORY_SEPARATOR . $prefix
            . '*.' . $this->escapeSuffixForGlob($this->getOptions()->getSuffix());
        return new FilesystemIterator($this, $path, $prefix);
    }

    /* OptimizableInterface */

    /**
     * Optimize the storage
     *
     * @throws Exception\RuntimeException
     */
    public function optimize(): bool
    {
        $options = $this->getOptions();
        if ($options->getDirLevel()) {
            $namespace = $options->getNamespace();
            $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();

            // removes only empty directories
            $this->clearAndDeleteDirectory($options->getCacheDir(), $prefix);
        }
        return true;
    }

    /* TotalSpaceCapableInterface */

    /**
     * Get total space in bytes
     *
     * @return int|float
     * @throws Exception\RuntimeException
     */
    public function getTotalSpace()
    {
        if ($this->totalSpace === null) {
            $path = $this->getOptions()->getCacheDir();

            $this->totalSpace = (float) $this->filesystem->totalBytes($path);
        }

        return $this->totalSpace;
    }

    /* AvailableSpaceCapableInterface */

    /**
     * Get available space in bytes
     *
     * @throws Exception\RuntimeException
     */
    public function getAvailableSpace(): float
    {
        $path = $this->getOptions()->getCacheDir();

        return (float) $this->filesystem->availableBytes($path);
    }

    /* reading */

    /**
     * Get an item.
     *
     * @param  string  $key
     * @param  bool $success
     * @param  mixed   $casToken
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     * @triggers getItem.pre(PreEvent)
     * @triggers getItem.post(PostEvent)
     * @triggers getItem.exception(ExceptionEvent)
     */
    public function getItem($key, &$success = null, &$casToken = null)
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        $argn = func_num_args();
        if ($argn > 2) {
            return parent::getItem($key, $success, $casToken);
        } elseif ($argn > 1) {
            return parent::getItem($key, $success);
        }

        return parent::getItem($key);
    }

    /**
     * Get multiple items.
     *
     * @param  array $keys
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     * @triggers getItems.pre(PreEvent)
     * @triggers getItems.post(PostEvent)
     * @triggers getItems.exception(ExceptionEvent)
     */
    public function getItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getItems($keys);
    }

    /**
     * Internal method to get an item.
     *
     * @param  string  $normalizedKey
     * @param  bool $success
     * @param  mixed   $casToken
     * @return null|mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     * @throws BaseException
     */
    protected function internalGetItem(&$normalizedKey, &$success = null, &$casToken = null)
    {
        try {
            $filespec = $this->formatFilename($this->getFileSpec($normalizedKey));
            //Don't use internalHasItem here to prevent reading file twice if exists
            if (! $this->filesystem->exists($filespec)) {
                $success = false;
                return null;
            }

            $data       = $this->getFileContent($filespec, false, $wouldBlock);
            $expiration = $this->getExpiration($data);

            if ($expiration && time() >= $expiration) {
                $this->deleteExpiredFile($filespec);
                $success = false;
                return null;
            }
            // use filemtime + filesize as CAS token
            if (func_num_args() > 2) {
                try {
                    $casToken = $this->filesystem->lastModifiedTime($filespec) . $this->filesystem->filesize($filespec);
                } catch (MetadataException $exception) {
                    $casToken = "";
                }
            }
            $success = true;
            return $data;
        } catch (BaseException $e) {
            $success = false;
            throw $e;
        }
    }

    /**
     * Internal method to get multiple items.
     *
     * @param  array $normalizedKeys
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItems(array &$normalizedKeys): array
    {
        $keys   = $normalizedKeys; // Don't change argument passed by reference
        $result = [];
        while ($keys) {
            // LOCK_NB if more than one items have to read
            $nonBlocking = count($keys) > 1;
            $wouldblock  = null;
            $now         = time();
            // read items
            foreach ($keys as $i => $key) {
                $filespec = $this->formatFilename($this->getFileSpec($key));
                //Don't use internalHasItem here to prevent reading the file twice if exists
                if (! $this->filesystem->exists($filespec)) {
                    unset($keys[$i]);
                    continue;
                }

                $data = $this->getFileContent($filespec, $nonBlocking, $wouldBlock);

                $expiration = $this->getExpiration($data);
                if ($expiration && $now >= $expiration) {
                    $this->deleteExpiredFile($filespec);
                    unset($keys[$i]);
                    continue;
                }

                if ($nonBlocking && $wouldblock) {
                    continue;
                } else {
                    unset($keys[$i]);
                }

                $result[$key] = $data;
            }
        }

        return $result;
    }

    /**
     * Test if an item exists.
     *
     * @param  string $key
     * @throws Exception\ExceptionInterface
     * @triggers hasItem.pre(PreEvent)
     * @triggers hasItem.post(PostEvent)
     * @triggers hasItem.exception(ExceptionEvent)
     */
    public function hasItem($key): bool
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::hasItem($key);
    }

    /**
     * Test multiple items.
     *
     * @param  array $keys
     * @return array Array of found keys
     * @throws Exception\ExceptionInterface
     * @triggers hasItems.pre(PreEvent)
     * @triggers hasItems.post(PostEvent)
     * @triggers hasItems.exception(ExceptionEvent)
     */
    public function hasItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::hasItems($keys);
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param  string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItem(&$normalizedKey): bool
    {
        $file = $this->formatFilename($this->getFileSpec($normalizedKey));
        if (! $this->filesystem->exists($file)) {
            return false;
        }

        if ($this->itemExpired($normalizedKey)) {
            return false;
        }

        return true;
    }

    /**
     * Get metadata
     *
     * @param string $key
     * @return array|bool Metadata on success, false on failure
     */
    public function getMetadata($key)
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getMetadata($key);
    }

    /**
     * Get metadatas
     *
     * @param array $keys
     * @param array $options
     * @return array Associative array of keys and metadata
     */
    public function getMetadatas(array $keys, array $options = []): array
    {
        $options = $this->getOptions();
        if ($options->getReadable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::getMetadatas($keys);
    }

    /**
     * Get info by key
     *
     * @param string $normalizedKey
     * @return array|bool Metadata on success, false on failure
     */
    protected function internalGetMetadata(&$normalizedKey)
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return false;
        }

        $options  = $this->getOptions();
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);

        try {
            $mtime = $this->filesystem->lastModifiedTime($file);
        } catch (Exception\RuntimeException $exception) {
            $mtime = false;
        }

        $metadata = [
            self::METADATA_FILESPEC => $filespec,
            self::METADATA_MTIME    => $mtime,
        ];

        if (! $options->getNoCtime()) {
            try {
                $ctime = $this->filesystem->createdTime($file);
            } catch (Exception\RuntimeException $exception) {
                $ctime = false;
            }
            $metadata[self::METADATA_CTIME] = $ctime;
        }

        if (! $options->getNoAtime()) {
            try {
                $atime = $this->filesystem->lastAccessedTime($file);
            } catch (Exception\RuntimeException $exception) {
                $atime = false;
            }
            $metadata[self::METADATA_ATIME] = $atime;
        }

        return $metadata;
    }

    /* writing */

    /**
     * Store an item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     * @triggers setItem.pre(PreEvent)
     * @triggers setItem.post(PostEvent)
     * @triggers setItem.exception(ExceptionEvent)
     */
    public function setItem($key, $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }
        return parent::setItem($key, $value);
    }

    /**
     * Store multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers setItems.pre(PreEvent)
     * @triggers setItems.post(PostEvent)
     * @triggers setItems.exception(ExceptionEvent)
     */
    public function setItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::setItems($keyValuePairs);
    }

    /**
     * Add an item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     * @triggers addItem.pre(PreEvent)
     * @triggers addItem.post(PostEvent)
     * @triggers addItem.exception(ExceptionEvent)
     */
    public function addItem($key, $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::addItem($key, $value);
    }

    /**
     * Add multiple items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers addItems.pre(PreEvent)
     * @triggers addItems.post(PostEvent)
     * @triggers addItems.exception(ExceptionEvent)
     */
    public function addItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::addItems($keyValuePairs);
    }

    /**
     * Replace an existing item.
     *
     * @param  string $key
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     * @triggers replaceItem.pre(PreEvent)
     * @triggers replaceItem.post(PostEvent)
     * @triggers replaceItem.exception(ExceptionEvent)
     */
    public function replaceItem($key, $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::replaceItem($key, $value);
    }

    /**
     * Replace multiple existing items.
     *
     * @param  array $keyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     * @triggers replaceItems.pre(PreEvent)
     * @triggers replaceItems.post(PostEvent)
     * @triggers replaceItems.exception(ExceptionEvent)
     */
    public function replaceItems(array $keyValuePairs): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::replaceItems($keyValuePairs);
    }

    /**
     * Internal method to store an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItem(&$normalizedKey, &$value): bool
    {
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);
        $this->prepareDirectoryStructure($filespec);

        $valueWithExpiration = $this->setExpiration((string) $value);

        // write data in non-blocking mode
        $this->putFileContent($file, (string) $valueWithExpiration, true, $wouldblock);

        // delete related tag file (if present)
        $this->filesystem->delete($this->formatTagFilename($filespec));

        // Retry writing data in blocking mode if it was blocked before
        if ($wouldblock) {
            $this->putFileContent($file, (string) $valueWithExpiration);
        }

        return true;
    }

    /**
     * Internal method to store multiple items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItems(array &$normalizedKeyValuePairs): array
    {
        // create an associated array of files and contents to write
        $contents = [];
        foreach ($normalizedKeyValuePairs as $key => &$value) {
            $filespec = $this->getFileSpec((string) $key);
            $this->prepareDirectoryStructure($filespec);

            $value = $this->setExpiration((string) $value);
            // *.dat file
            $contents[$this->formatFilename($filespec)] = &$value;

            // *.tag file
            $this->filesystem->delete($this->formatTagFilename($filespec));
        }

        // write to disk
        while ($contents) {
            $nonBlocking = count($contents) > 1;

            foreach ($contents as $file => &$content) {
                $wouldblock = null;
                $this->putFileContent($file, (string) $content, $nonBlocking, $wouldblock);
                if (! $nonBlocking || ! $wouldblock) {
                    unset($contents[$file]);
                }
            }
        }

        // return OK
        return [];
    }

    /**
     * Set an item only if token matches
     *
     * It uses the token received from getItem() to check if the item has
     * changed before overwriting it.
     *
     * @see    Filesystem::setItem()
     * @see    Filesystem::getItem()
     *
     * @param  mixed  $token
     * @param  string $key
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     */
    public function checkAndSetItem($token, $key, $value): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::checkAndSetItem($token, $key, $value);
    }

    /**
     * Internal method to set an item only if token matches
     *
     * @see    Filesystem::setItem()
     * @see    Filesystem::getItem()
     *
     * @param  mixed  $token
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @throws Exception\ExceptionInterface
     */
    protected function internalCheckAndSetItem(&$token, &$normalizedKey, &$value): bool
    {
        if (! $this->internalHasItem($normalizedKey)) {
            return false;
        }

        // use filemtime + filesize as CAS token
        $file = $this->formatFilename($this->getFileSpec($normalizedKey));
        try {
            $check = $this->filesystem->lastModifiedTime($file) . $this->filesystem->filesize($file);
        } catch (MetadataException $exception) {
            $check = "";
        }

        if ($token !== $check) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * Reset lifetime of an item
     *
     * @param  string $key
     * @throws Exception\ExceptionInterface
     * @triggers touchItem.pre(PreEvent)
     * @triggers touchItem.post(PostEvent)
     * @triggers touchItem.exception(ExceptionEvent)
     */
    public function touchItem($key): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::touchItem($key);
    }

    /**
     * Reset lifetime of multiple items.
     *
     * @param  array $keys
     * @return array Array of not updated keys
     * @throws Exception\ExceptionInterface
     * @triggers touchItems.pre(PreEvent)
     * @triggers touchItems.post(PostEvent)
     * @triggers touchItems.exception(ExceptionEvent)
     */
    public function touchItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::touchItems($keys);
    }

    /**
     * Internal method to reset lifetime of an item
     *
     * @param  string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalTouchItem(&$normalizedKey): bool
    {
        $data = $this->internalGetItem($normalizedKey, $success);

        if ($success === false) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $data);
    }

    /**
     * Remove an item.
     *
     * @param  string $key
     * @throws Exception\ExceptionInterface
     * @triggers removeItem.pre(PreEvent)
     * @triggers removeItem.post(PostEvent)
     * @triggers removeItem.exception(ExceptionEvent)
     */
    public function removeItem($key): bool
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::removeItem($key);
    }

    /**
     * Remove multiple items.
     *
     * @param  array $keys
     * @return array Array of not removed keys
     * @throws Exception\ExceptionInterface
     * @triggers removeItems.pre(PreEvent)
     * @triggers removeItems.post(PostEvent)
     * @triggers removeItems.exception(ExceptionEvent)
     */
    public function removeItems(array $keys): array
    {
        $options = $this->getOptions();
        if ($options->getWritable() && $options->getClearStatCache()) {
            $this->filesystem->clearStatCache();
        }

        return parent::removeItems($keys);
    }

    /**
     * Internal method to remove an item.
     *
     * @param  string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItem(&$normalizedKey): bool
    {
        $filespec = $this->getFileSpec($normalizedKey);
        $file     = $this->formatFilename($filespec);
        if (! $this->filesystem->exists($file)) {
            return false;
        }

        $this->filesystem->delete($file);
        $this->filesystem->delete($this->formatTagFilename($filespec));
        return true;
    }

    /* status */

    /**
     * Internal method to get capabilities of this adapter
     */
    protected function internalGetCapabilities(): Capabilities
    {
        if ($this->capabilities === null) {
            $marker  = new stdClass();
            $options = $this->getOptions();

            // detect metadata
            $metadata = [self::METADATA_MTIME, self::METADATA_FILESPEC];
            if (! $options->getNoAtime()) {
                $metadata[] = self::METADATA_ATIME;
            }
            if (! $options->getNoCtime()) {
                $metadata[] = self::METADATA_CTIME;
            }

            // Calculate max key length: 255 - strlen(.) - strlen(dat | tag)
            $maxKeyLength = 255 - 1 - max([
                strlen($options->getSuffix()),
                strlen($options->getTagSuffix()),
            ]);

            $namespace = $options->getNamespace();
            if ($namespace !== '') {
                $maxKeyLength -= strlen($namespace) + strlen($options->getNamespaceSeparator());
            }

            if ($maxKeyLength < 1) {
                throw new Exception\RuntimeException(
                    'Invalid maximum key length was calculated.'
                    . ' This usually happens if the used namespace is too long.'
                );
            }

            $capabilities = new Capabilities(
                $this,
                $marker,
                [
                    'supportedDatatypes' => [
                        'NULL'     => 'string',
                        'boolean'  => 'string',
                        'integer'  => 'string',
                        'double'   => 'string',
                        'string'   => true,
                        'array'    => false,
                        'object'   => false,
                        'resource' => false,
                    ],
                    'supportedMetadata'  => $metadata,
                    'minTtl'             => 1,
                    'maxTtl'             => 0,
                    'staticTtl'          => true,
                    'ttlPrecision'       => 1,
                    'maxKeyLength'       => $maxKeyLength,
                    'namespaceIsPrefix'  => true,
                    'namespaceSeparator' => $options->getNamespaceSeparator(),
                ]
            );

            // update capabilities on change options
            $this->getEventManager()->attach('option', static function ($event) use ($capabilities, $marker): void {
                $params = $event->getParams();

                if (isset($params['namespace_separator'])) {
                    $capabilities->setNamespaceSeparator($marker, $params['namespace_separator']);
                }

                if (isset($params['no_atime']) || isset($params['no_ctime'])) {
                    $metadata = $capabilities->getSupportedMetadata();

                    if (isset($params['no_atime']) && ! $params['no_atime']) {
                        $metadata[] = self::METADATA_ATIME;
                    } elseif (
                        isset($params['no_atime'])
                        && ($index = array_search(self::METADATA_ATIME, $metadata)) !== false
                    ) {
                        unset($metadata[$index]);
                    }

                    if (isset($params['no_ctime']) && ! $params['no_ctime']) {
                        $metadata[] = self::METADATA_CTIME;
                    } elseif (
                        isset($params['no_ctime'])
                        && ($index = array_search(self::METADATA_CTIME, $metadata)) !== false
                    ) {
                        unset($metadata[$index]);
                    }

                    $capabilities->setSupportedMetadata($marker, $metadata);
                }
            });

            $this->capabilityMarker = $marker;
            $this->capabilities     = $capabilities;
        }

        return $this->capabilities;
    }

    /* internal */

    /**
     * Removes directories recursive by namespace
     */
    private function clearAndDeleteDirectory(string $dir, string $prefix): bool
    {
        $glob = glob(
            $dir . DIRECTORY_SEPARATOR . $prefix . '*',
            GLOB_ONLYDIR | GLOB_NOESCAPE | GLOB_NOSORT
        );
        if (! $glob) {
            // On some systems glob returns false even on empty result
            return true;
        }

        $ret = true;
        foreach ($glob as $subdir) {
            // skip removing current directory if removing of sub-directory failed
            if ($this->clearAndDeleteDirectory($subdir, $prefix)) {
                // ignore not empty directories
                ErrorHandler::start();
                $ret = rmdir($subdir) && $ret;
                ErrorHandler::stop();
            } else {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Get file spec of the given key and namespace
     */
    private function getFileSpec(string $normalizedKey): string
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator();
        $path      = $options->getCacheDir() . DIRECTORY_SEPARATOR;
        $level     = $options->getDirLevel();

        $fileSpecId = $path . $prefix . $normalizedKey . '/' . $level;
        if ($this->lastFileSpecId !== $fileSpecId) {
            if ($level > 0) {
                // create up to 256 directories per directory level
                $hash = md5($normalizedKey);
                for ($i = 0, $max = $level * 2; $i < $max; $i += 2) {
                    $path .= $prefix . $hash[$i] . $hash[$i + 1] . DIRECTORY_SEPARATOR;
                }
            }

            $this->lastFileSpecId = $fileSpecId;
            $this->lastFileSpec   = $path . $prefix . $normalizedKey;
        }

        return $this->lastFileSpec;
    }

    /**
     * Read a complete file
     *
     * @param  string  $file        File complete path
     * @param  bool $nonBlocking Don't block script if file is locked
     * @param  bool $wouldblock  The optional argument is set to TRUE if the lock would block
     * @throws Exception\RuntimeException
     */
    private function getFileContent(string $file, bool $nonBlocking = false, ?bool &$wouldblock = null): string
    {
        $options = $this->getOptions();
        $locking = $options->getFileLocking();

        return $this->filesystem->read($file, $locking, $nonBlocking, $wouldblock);
    }

    /**
     * Prepares a directory structure for the given file(spec)
     * using the configured directory level.
     *
     * @throws Exception\RuntimeException
     */
    private function prepareDirectoryStructure(string $file): void
    {
        $options = $this->getOptions();
        $level   = $options->getDirLevel();

        // Directory structure is required only if directory level > 0
        if (! $level) {
            return;
        }

        // Directory structure already exists
        $pathname = dirname($file);
        if ($this->filesystem->exists($pathname)) {
            return;
        }

        $perm  = $options->getDirPermission();
        $umask = $options->getUmask();
        if ($umask !== false && $perm !== false) {
            $perm &= ~$umask;
        }

        ErrorHandler::start();

        if ($perm === false || $level === 1) {
            $this->filesystem->createDirectory(
                $pathname,
                $perm !== false ? $perm : 0775,
                true,
                $umask !== false ? $umask : null
            );
        } else {
            // built-in mkdir function sets permission together with current umask
            // which doesn't work well on multi threaded webservers
            // -> create directories one by one and set permissions

            // find existing path and missing path parts
            $parts = [];
            $path  = $pathname;
            while (! $this->filesystem->exists($path)) {
                array_unshift($parts, basename($path));
                $nextPath = dirname($path);
                if ($nextPath === $path) {
                    break;
                }
                $path = $nextPath;
            }

            // make all missing path parts
            foreach ($parts as $part) {
                $path .= DIRECTORY_SEPARATOR . $part;

                // create a single directory, set and reset umask immediately
                $this->filesystem->createDirectory(
                    $path,
                    0775,
                    false,
                    $umask !== false ? $umask : null
                );
            }
        }

        ErrorHandler::stop();
    }

    /**
     * Write content to a file
     *
     * @param  bool $nonBlocking Don't block script if file is locked
     * @param  bool|null $wouldblock  The optional argument is set to true if the lock would block
     * @throws Exception\RuntimeException
     */
    private function putFileContent(
        string $file,
        string $data,
        bool $nonBlocking = false,
        ?bool &$wouldblock = null
    ): void {
        $options     = $this->getOptions();
        $umask       = $options->getUmask();
        $permissions = $options->getFilePermission();
        $this->filesystem->write(
            $file,
            $data,
            $umask !== false ? $umask : null,
            $permissions !== false ? $permissions : null,
            $options->getFileLocking(),
            $nonBlocking,
            $wouldblock
        );
    }

    /**
     * Formats the filename, appending the suffix option
     */
    private function formatFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, $this->getOptions()->getSuffix());
    }

    /**
     * Formats the filename, appending the tag suffix option
     */
    private function formatTagFilename(string $filename): string
    {
        return sprintf('%s.%s', $filename, $this->getOptions()->getTagSuffix());
    }

    /**
     * Escapes a filename suffix to be safe for glob operations
     *
     * Wraps any of *, ?, or [ characters within [] brackets.
     */
    private function escapeSuffixForGlob(string $suffix): string
    {
        return preg_replace('#([*?\[])#', '[$1]', $suffix);
    }

    private function getExpiration(?string &$data): ?int
    {
        if ($data && preg_match('/^##(.*)##\n/', $data, $matches)) {
            $data = str_replace($matches[0], '', $data);

            return (int) $matches[1];
        }

        return null;
    }

    private function setExpiration(string $value): string
    {
        $expiration = '';
        $ttl        = $this->getOptions()->getTtl();

        if ($ttl > 0) {
            $expiration = time() + $ttl;
        }

        return '##' . $expiration . '##' . "\n" . $value;
    }

    private function itemExpired(string $normalizedKey): bool
    {
        $filespec = $this->formatFilename($this->getFileSpec($normalizedKey));
        ErrorHandler::start();

        try {
            $expired = $this->filesystem->expired($filespec);
        } catch (UnlinkException $exception) {
            if ($this->filesystem->exists($filespec)) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine()
                );
            }

            $expired = true;
        }

        if ($expired) {
            try {
                $this->filesystem->delete($this->formatTagFilename($filespec));
            } catch (UnlinkException $exception) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine()
                );
            }
        }

        $error = ErrorHandler::stop();

        //Failure to delete expired cache shouldn't interrupt program flow
        //Trigger Exception event without throwing
        if ($error) {
            $result         = true;
            $exceptionEvent = new ExceptionEvent(
                __FUNCTION__ . '.exception',
                $this,
                new ArrayObject(),
                $result,
                new Exception\RuntimeException("Expired file '{$filespec}' was not deleted ", 0, $error)
            );
            $eventRs        = $this->getEventManager()->triggerEvent($exceptionEvent);

            return $eventRs->stopped()
                ? $eventRs->last()
                : $exceptionEvent->getResult();
        }

        return $expired;
    }

    private function deleteExpiredFile(string $file): void
    {
        ErrorHandler::start();

        try {
            $result = $this->filesystem->delete($file);
        } catch (UnlinkException $exception) {
            if ($this->filesystem->exists($file)) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine()
                );
            }

            $result = true;
        }

        if ($result) {
            $tagPathname = $this->formatTagFilename($file);
            try {
                $this->filesystem->delete($tagPathname);
            } catch (UnlinkException $exception) {
                ErrorHandler::addError(
                    $exception->getErrorSeverity(),
                    $exception->getErrorMessage(),
                    $exception->getErrorFile(),
                    $exception->getErrorLine()
                );
            }
        }

        //Failure to delete invalid cache shouldn't interrupt program flow
        //Trigger ExceptionEvent without throwing
        $err = ErrorHandler::stop();
        if ($err) {
            $result         = true;
            $exceptionEvent = new ExceptionEvent(
                __FUNCTION__ . '.exception',
                $this,
                new ArrayObject(),
                $result,
                new Exception\RuntimeException("Failed to delete expired file '{$file}'", 0, $err)
            );
            $this->getEventManager()->triggerEvent($exceptionEvent);
        }
    }
}
