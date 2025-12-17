<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithMappingInterface
 *
 * Implement this interface to map column headers to custom keys.
 */
interface WithMappingInterface
{
    /**
     * Map column headers to custom keys.
     *
     * @param array<string|int, mixed> $row Original row data
     * @return array<string, mixed> Mapped row data
     *
     * @example
     * public function map(array $row): array
     * {
     *     return [
     *         'user_email' => $row['Email Address'] ?? $row[0],
     *         'user_name' => $row['Full Name'] ?? $row[1],
     *         'user_age' => (int) ($row['Age'] ?? $row[2]),
     *     ];
     * }
     */
    public function map(array $row): array;
}
