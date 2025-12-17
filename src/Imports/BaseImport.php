<?php

declare(strict_types=1);

namespace Toporia\Tabula\Imports;

use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\ShouldQueueInterface;
use Toporia\Tabula\Contracts\WithBatchInsertsInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithEventsInterface;
use Toporia\Tabula\Contracts\WithHeadingRowInterface;
use Toporia\Tabula\Contracts\WithMappingInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Contracts\WithValidationInterface;

/**
 * Class BaseImport
 *
 * Base class for import definitions.
 * Extend this class to create custom import handlers.
 *
 * @example
 * class UsersImport extends BaseImport implements WithChunkReadingInterface
 * {
 *     public function row(array $row, int $rowNumber): void
 *     {
 *         User::create([
 *             'name' => $row['name'],
 *             'email' => $row['email'],
 *         ]);
 *     }
 *
 *     public function chunkSize(): int
 *     {
 *         return 1000;
 *     }
 * }
 */
abstract class BaseImport implements ImportableInterface
{
    /**
     * Get the heading row number (1-indexed).
     *
     * @return int
     */
    public function headingRow(): int
    {
        return 1;
    }

    /**
     * Get chunk size for reading.
     *
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Get batch size for database inserts.
     *
     * @return int
     */
    public function batchSize(): int
    {
        return 500;
    }

    /**
     * Get validation rules.
     *
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function customValidationMessages(): array
    {
        return [];
    }

    /**
     * Get the queue name for background processing.
     *
     * @return string
     */
    public function queue(): string
    {
        return 'default';
    }

    /**
     * Register event handlers.
     *
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [];
    }

    /**
     * Check if this import has a heading row.
     *
     * @return bool
     */
    public function hasHeadingRow(): bool
    {
        return $this instanceof WithHeadingRowInterface || method_exists($this, 'headingRow');
    }

    /**
     * Check if this import uses chunk reading.
     *
     * @return bool
     */
    public function usesChunkReading(): bool
    {
        return $this instanceof WithChunkReadingInterface;
    }

    /**
     * Check if this import uses batch inserts.
     *
     * @return bool
     */
    public function usesBatchInserts(): bool
    {
        return $this instanceof WithBatchInsertsInterface;
    }

    /**
     * Check if this import has validation.
     *
     * @return bool
     */
    public function hasValidation(): bool
    {
        return $this instanceof WithValidationInterface;
    }

    /**
     * Check if this import has mapping.
     *
     * @return bool
     */
    public function hasMapping(): bool
    {
        return $this instanceof WithMappingInterface;
    }

    /**
     * Check if this import should be queued.
     *
     * @return bool
     */
    public function shouldQueue(): bool
    {
        return $this instanceof ShouldQueueInterface;
    }

    /**
     * Check if this import has events.
     *
     * @return bool
     */
    public function hasEvents(): bool
    {
        return $this instanceof WithEventsInterface;
    }

    /**
     * Check if this import tracks progress.
     *
     * @return bool
     */
    public function tracksProgress(): bool
    {
        return $this instanceof WithProgressInterface;
    }
}
