<?php

declare(strict_types=1);

namespace Toporia\Tabula\Events;

use Toporia\Tabula\Support\ExportResult;

/**
 * Class ExportCompleted
 *
 * Event fired when an export completes.
 */
final class ExportCompleted
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $exportClass,
        public readonly ExportResult $result,
    ) {
    }
}
