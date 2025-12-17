<?php

declare(strict_types=1);

namespace Toporia\Tabula\Support;

/**
 * Class ExportResult
 *
 * Value object representing the result of an export operation.
 */
final class ExportResult
{
    public function __construct(
        private string $filePath,
        private int $totalRows = 0,
        private float $duration = 0.0,
        private int $fileSize = 0,
        private bool $successful = true,
        private ?string $errorMessage = null,
    ) {
    }

    /**
     * Get the file path.
     *
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get total rows exported.
     *
     * @return int
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
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
     * Get file size in bytes.
     *
     * @return int
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Get file size as human-readable string.
     *
     * @return string
     */
    public function getFileSizeFormatted(): string
    {
        $bytes = $this->fileSize;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $unitIndex = 0;
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
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
     * Check if export was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get error message if export failed.
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_path' => $this->filePath,
            'total_rows' => $this->totalRows,
            'duration' => round($this->duration, 3),
            'rows_per_second' => round($this->getRowsPerSecond(), 2),
            'file_size' => $this->fileSize,
            'file_size_formatted' => $this->getFileSizeFormatted(),
            'is_successful' => $this->successful,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Create a successful result.
     *
     * @param string $filePath
     * @param int $totalRows
     * @param float $duration
     * @return self
     */
    public static function success(string $filePath, int $totalRows, float $duration = 0.0): self
    {
        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;

        return new self(
            filePath: $filePath,
            totalRows: $totalRows,
            duration: $duration,
            fileSize: $fileSize ?: 0,
            successful: true,
        );
    }

    /**
     * Create a failed result.
     *
     * @param string $filePath
     * @param string $errorMessage
     * @return self
     */
    public static function failed(string $filePath, string $errorMessage): self
    {
        return new self(
            filePath: $filePath,
            totalRows: 0,
            duration: 0.0,
            fileSize: 0,
            successful: false,
            errorMessage: $errorMessage,
        );
    }
}
