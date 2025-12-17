<?php

declare(strict_types=1);

namespace Toporia\Tabula\Events;

/**
 * Class ExportStarted
 *
 * Event fired when an export starts.
 */
final class ExportStarted
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $exportClass,
    ) {
    }
}
