<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithHeadingRowInterface
 *
 * Implement this interface to indicate that the import file has a header row.
 * The first row will be used as keys for subsequent rows.
 */
interface WithHeadingRowInterface
{
    /**
     * Get the row number that contains the headings.
     *
     * @return int Row number (1-indexed, default: 1)
     */
    public function headingRow(): int;
}
