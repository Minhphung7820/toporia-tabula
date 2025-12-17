<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exceptions;

/**
 * Class ExportException
 *
 * Exception thrown during export operations.
 */
class ExportException extends TabulaException
{
    /**
     * Create exception for file write failure.
     *
     * @param string $filePath
     * @return self
     */
    public static function cannotWriteFile(string $filePath): self
    {
        return new self("Cannot write to file: {$filePath}");
    }

    /**
     * Create exception for unsupported file type.
     *
     * @param string $extension
     * @return self
     */
    public static function unsupportedFileType(string $extension): self
    {
        return new self("Unsupported export file type: {$extension}");
    }

    /**
     * Create exception for directory creation failure.
     *
     * @param string $directory
     * @return self
     */
    public static function cannotCreateDirectory(string $directory): self
    {
        return new self("Cannot create directory: {$directory}");
    }
}
