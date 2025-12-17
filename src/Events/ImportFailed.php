<?php

declare(strict_types=1);

namespace Toporia\Tabula\Events;

/**
 * Class ImportFailed
 *
 * Event fired when an import fails.
 */
final class ImportFailed
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $importClass,
        public readonly \Throwable $exception,
    ) {
    }
}
