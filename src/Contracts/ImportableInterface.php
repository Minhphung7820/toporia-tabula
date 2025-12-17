<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface ImportableInterface
 *
 * Contract for import classes that handle Excel/CSV imports.
 */
interface ImportableInterface
{
    /**
     * Process each row from the import.
     *
     * @param array<string|int, mixed> $row Row data (keyed by header or index)
     * @param int $rowNumber Row number (1-indexed)
     * @return void
     */
    public function row(array $row, int $rowNumber): void;
}
