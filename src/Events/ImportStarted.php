<?php

declare(strict_types=1);

namespace Toporia\Tabula\Events;

/**
 * Class ImportStarted
 *
 * Event fired when an import starts.
 */
final class ImportStarted
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $importClass,
    ) {
    }
}
