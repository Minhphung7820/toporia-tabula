<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithColumnFormattingInterface
 *
 * Implement this interface to format column values for export.
 */
interface WithColumnFormattingInterface
{
    /**
     * Get column formatters.
     *
     * @return array<string, callable> Column name => formatter function
     *
     * @example
     * public function columnFormats(): array
     * {
     *     return [
     *         'price' => fn($value) => number_format($value, 2),
     *         'created_at' => fn($value) => $value?->format('Y-m-d'),
     *         'is_active' => fn($value) => $value ? 'Yes' : 'No',
     *     ];
     * }
     */
    public function columnFormats(): array;
}
