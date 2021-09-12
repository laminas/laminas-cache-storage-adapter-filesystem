<?php

declare(strict_types=1);

namespace Laminas\Cache\Storage\Adapter;

use function usleep;

/**
 * @param string        $path
 * @param resource|null $context
 * @return bool
 */
function unlink($path, $context = null)
{
    $unlinkDelay = $GLOBALS['unlinkDelay'] ?? null;
    if ($unlinkDelay > 0) {
        usleep($unlinkDelay);
    }

    return $context !== null ? \unlink($path, $context) : \unlink($path);
}
