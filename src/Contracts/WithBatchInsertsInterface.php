<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithBatchInsertsInterface
 *
 * Implement this interface to batch database inserts for better performance.
 * Instead of inserting row by row, rows are collected and inserted in batches.
 */
interface WithBatchInsertsInterface
{
    /**
     * Get the batch size for database inserts.
     *
     * Recommended values:
     * - Simple tables: 1000
     * - Complex tables with many columns: 500
     * - Tables with large text fields: 100
     *
     * @return int Number of rows per batch insert
     */
    public function batchSize(): int;
}
