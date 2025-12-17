<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface ToModelInterface
 *
 * Implement this interface to import directly to a model.
 * Provides automatic model creation with mass assignment.
 */
interface ToModelInterface
{
    /**
     * Get the model class to import to.
     *
     * @return string Fully qualified model class name
     *
     * @example
     * public function model(): string
     * {
     *     return User::class;
     * }
     */
    public function model(): string;

    /**
     * Map row data to model attributes.
     *
     * Return null to skip the row.
     *
     * @param array<string|int, mixed> $row Row data
     * @return array<string, mixed>|null Model attributes or null to skip
     *
     * @example
     * public function modelData(array $row): ?array
     * {
     *     return [
     *         'name' => $row['name'],
     *         'email' => strtolower($row['email']),
     *         'created_at' => now(),
     *     ];
     * }
     */
    public function modelData(array $row): ?array;
}
