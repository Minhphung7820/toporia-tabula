<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exceptions;

/**
 * Class ImportException
 *
 * Exception thrown during import operations.
 */
class ImportException extends TabulaException
{
    /**
     * @var int|null Row number where error occurred
     */
    protected ?int $rowNumber = null;

    /**
     * @var array<string, mixed> Row data when error occurred
     */
    protected array $rowData = [];

    /**
     * @var array<array{row: int, message: string}> Collection of row errors
     */
    protected array $rowErrors = [];

    /**
     * Set the row number where error occurred.
     *
     * @param int $rowNumber
     * @return self
     */
    public function setRowNumber(int $rowNumber): self
    {
        $this->rowNumber = $rowNumber;
        return $this;
    }

    /**
     * Get the row number where error occurred.
     *
     * @return int|null
     */
    public function getRowNumber(): ?int
    {
        return $this->rowNumber;
    }

    /**
     * Set the row data when error occurred.
     *
     * @param array<string, mixed> $rowData
     * @return self
     */
    public function setRowData(array $rowData): self
    {
        $this->rowData = $rowData;
        return $this;
    }

    /**
     * Get the row data when error occurred.
     *
     * @return array<string, mixed>
     */
    public function getRowData(): array
    {
        return $this->rowData;
    }

    /**
     * Add a row error.
     *
     * @param int $row Row number
     * @param string $message Error message
     * @return self
     */
    public function addRowError(int $row, string $message): self
    {
        $this->rowErrors[] = [
            'row' => $row,
            'message' => $message,
        ];
        return $this;
    }

    /**
     * Get all row errors.
     *
     * @return array<array{row: int, message: string}>
     */
    public function getRowErrors(): array
    {
        return $this->rowErrors;
    }

    /**
     * Create exception for file not found.
     *
     * @param string $filePath
     * @return self
     */
    public static function fileNotFound(string $filePath): self
    {
        return new self("Import file not found: {$filePath}");
    }

    /**
     * Create exception for unsupported file type.
     *
     * @param string $extension
     * @return self
     */
    public static function unsupportedFileType(string $extension): self
    {
        return new self("Unsupported file type: {$extension}");
    }

    /**
     * Create exception for row validation failure.
     *
     * @param int $rowNumber
     * @param array<string> $errors
     * @return self
     */
    public static function validationFailed(int $rowNumber, array $errors): self
    {
        $exception = new self(
            "Validation failed on row {$rowNumber}: " . implode(', ', $errors)
        );
        $exception->setRowNumber($rowNumber);
        return $exception;
    }
}
