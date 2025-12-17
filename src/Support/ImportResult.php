<?php

declare(strict_types=1);

namespace Toporia\Tabula\Support;

/**
 * Class ImportResult
 *
 * Value object representing the result of an import operation.
 */
final class ImportResult
{
    /**
     * @var array<array{row: int, message: string, data?: array}> Row-level errors
     */
    private array $errors = [];

    /**
     * @var array<string> Warning messages
     */
    private array $warnings = [];

    public function __construct(
        private int $totalRows = 0,
        private int $successRows = 0,
        private int $failedRows = 0,
        private int $skippedRows = 0,
        private float $duration = 0.0,
    ) {
    }

    /**
     * Get total rows processed.
     *
     * @return int
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    /**
     * Get number of successful rows.
     *
     * @return int
     */
    public function getSuccessRows(): int
    {
        return $this->successRows;
    }

    /**
     * Get number of failed rows.
     *
     * @return int
     */
    public function getFailedRows(): int
    {
        return $this->failedRows;
    }

    /**
     * Get number of skipped rows.
     *
     * @return int
     */
    public function getSkippedRows(): int
    {
        return $this->skippedRows;
    }

    /**
     * Get duration in seconds.
     *
     * @return float
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get rows per second throughput.
     *
     * @return float
     */
    public function getRowsPerSecond(): float
    {
        if ($this->duration <= 0) {
            return 0.0;
        }
        return $this->totalRows / $this->duration;
    }

    /**
     * Check if import was successful (no failed rows).
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->failedRows === 0;
    }

    /**
     * Check if import has errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->failedRows > 0 || !empty($this->errors);
    }

    /**
     * Add an error.
     *
     * @param int $row Row number
     * @param string $message Error message
     * @param array<string, mixed> $data Optional row data
     * @return self
     */
    public function addError(int $row, string $message, array $data = []): self
    {
        $error = [
            'row' => $row,
            'message' => $message,
        ];

        if (!empty($data)) {
            $error['data'] = $data;
        }

        $this->errors[] = $error;
        return $this;
    }

    /**
     * Get all errors.
     *
     * @return array<array{row: int, message: string, data?: array}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add a warning.
     *
     * @param string $message Warning message
     * @return self
     */
    public function addWarning(string $message): self
    {
        $this->warnings[] = $message;
        return $this;
    }

    /**
     * Get all warnings.
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Increment total rows.
     *
     * @param int $count
     * @return self
     */
    public function incrementTotal(int $count = 1): self
    {
        $this->totalRows += $count;
        return $this;
    }

    /**
     * Increment success rows.
     *
     * @param int $count
     * @return self
     */
    public function incrementSuccess(int $count = 1): self
    {
        $this->successRows += $count;
        return $this;
    }

    /**
     * Increment failed rows.
     *
     * @param int $count
     * @return self
     */
    public function incrementFailed(int $count = 1): self
    {
        $this->failedRows += $count;
        return $this;
    }

    /**
     * Increment skipped rows.
     *
     * @param int $count
     * @return self
     */
    public function incrementSkipped(int $count = 1): self
    {
        $this->skippedRows += $count;
        return $this;
    }

    /**
     * Set duration.
     *
     * @param float $duration Duration in seconds
     * @return self
     */
    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * Merge another result into this one.
     *
     * @param ImportResult $other
     * @return self
     */
    public function merge(ImportResult $other): self
    {
        $this->totalRows += $other->totalRows;
        $this->successRows += $other->successRows;
        $this->failedRows += $other->failedRows;
        $this->skippedRows += $other->skippedRows;
        $this->errors = array_merge($this->errors, $other->errors);
        $this->warnings = array_merge($this->warnings, $other->warnings);

        return $this;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'success_rows' => $this->successRows,
            'failed_rows' => $this->failedRows,
            'skipped_rows' => $this->skippedRows,
            'duration' => round($this->duration, 3),
            'rows_per_second' => round($this->getRowsPerSecond(), 2),
            'is_successful' => $this->isSuccessful(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Create a successful result.
     *
     * @param int $totalRows
     * @param float $duration
     * @return self
     */
    public static function success(int $totalRows, float $duration = 0.0): self
    {
        return new self(
            totalRows: $totalRows,
            successRows: $totalRows,
            failedRows: 0,
            skippedRows: 0,
            duration: $duration,
        );
    }
}
