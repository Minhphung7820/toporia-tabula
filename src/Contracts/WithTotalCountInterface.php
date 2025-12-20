<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithTotalCountInterface
 *
 * Provides total row count for progress tracking.
 * Useful when data source is a Generator (not Countable).
 */
interface WithTotalCountInterface
{
    /**
     * Get total number of rows to export.
     *
     * @return int Total row count (0 if unknown)
     */
    public function totalCount(): int;
}
