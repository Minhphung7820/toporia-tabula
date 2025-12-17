<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithMultipleSheetsInterface
 *
 * Implement this interface for exports with multiple sheets.
 */
interface WithMultipleSheetsInterface
{
    /**
     * Get the sheets to export.
     *
     * @return array<ExportableInterface> Array of exportable sheets
     *
     * @example
     * return [
     *     new UsersSheet(),
     *     new OrdersSheet(),
     *     new ProductsSheet(),
     * ];
     */
    public function sheets(): array;
}
