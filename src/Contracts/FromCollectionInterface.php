<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface FromCollectionInterface
 *
 * Implement this interface to export from a collection or array.
 */
interface FromCollectionInterface
{
    /**
     * Get the collection for export.
     *
     * @return iterable<mixed>
     *
     * @example
     * public function collection(): iterable
     * {
     *     return User::all();
     * }
     */
    public function collection(): iterable;
}
