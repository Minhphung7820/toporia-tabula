<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithTitleInterface
 *
 * Implement this interface to set the sheet title.
 */
interface WithTitleInterface
{
    /**
     * Get the sheet title.
     *
     * @return string Sheet title (max 31 characters for Excel compatibility)
     */
    public function title(): string;
}
