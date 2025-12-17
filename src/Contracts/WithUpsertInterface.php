<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithUpsertInterface
 *
 * Implement this interface to use upsert (insert or update) for imports.
 * Much faster than checking existence for each row.
 */
interface WithUpsertInterface
{
    /**
     * Get unique key columns for upsert.
     *
     * These columns are used to determine if a row should be inserted or updated.
     *
     * @return array<string> Column names that form the unique key
     *
     * @example
     * public function uniqueBy(): array
     * {
     *     return ['email']; // Single column
     *     // or
     *     return ['product_id', 'warehouse_id']; // Composite key
     * }
     */
    public function uniqueBy(): array;

    /**
     * Get columns to update on conflict.
     *
     * Return null to update all columns.
     *
     * @return array<string>|null Columns to update or null for all
     *
     * @example
     * public function upsertColumns(): ?array
     * {
     *     return ['name', 'price', 'updated_at']; // Only update these
     *     // or
     *     return null; // Update all columns
     * }
     */
    public function upsertColumns(): ?array;
}
