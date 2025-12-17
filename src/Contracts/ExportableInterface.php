<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface ExportableInterface
 *
 * Contract for export classes that handle Excel/CSV exports.
 */
interface ExportableInterface
{
    /**
     * Get the data to export.
     *
     * Performance: Should return a Generator for large datasets
     * to maintain O(1) memory usage.
     *
     * @return iterable<array<mixed>>
     */
    public function data(): iterable;

    /**
     * Get the column headers.
     *
     * @return array<string>
     */
    public function headers(): array;
}
