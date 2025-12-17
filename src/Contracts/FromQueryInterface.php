<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface FromQueryInterface
 *
 * Implement this interface to export from a database query.
 * Uses cursor() for memory-efficient iteration.
 */
interface FromQueryInterface
{
    /**
     * Get the query builder for export.
     *
     * The query should NOT call get() - Tabula will use cursor()
     * for memory-efficient streaming.
     *
     * @return mixed Query builder instance
     *
     * @example
     * public function query(): mixed
     * {
     *     return User::query()
     *         ->where('active', true)
     *         ->orderBy('created_at', 'desc');
     * }
     */
    public function query(): mixed;
}
