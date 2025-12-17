<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exceptions;

/**
 * Class TabulaException
 *
 * Base exception for all Tabula-related errors.
 */
class TabulaException extends \Exception
{
    /**
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
