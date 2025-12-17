<?php

declare(strict_types=1);

namespace Toporia\Tabula\Exports;

use Toporia\Tabula\Contracts\ExportableInterface;
use Toporia\Tabula\Contracts\ShouldQueueInterface;
use Toporia\Tabula\Contracts\WithEventsInterface;
use Toporia\Tabula\Contracts\WithMultipleSheetsInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Contracts\WithTitleInterface;

/**
 * Class BaseExport
 *
 * Base class for export definitions.
 * Extend this class to create custom export handlers.
 *
 * @example
 * class UsersExport extends BaseExport
 * {
 *     public function data(): iterable
 *     {
 *         // Use generator for large datasets
 *         foreach (User::query()->cursor() as $user) {
 *             yield [
 *                 'name' => $user->name,
 *                 'email' => $user->email,
 *             ];
 *         }
 *     }
 *
 *     public function headers(): array
 *     {
 *         return ['Name', 'Email'];
 *     }
 * }
 */
abstract class BaseExport implements ExportableInterface
{
    /**
     * Get the sheet title.
     *
     * @return string
     */
    public function title(): string
    {
        return 'Sheet1';
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    public function queue(): string
    {
        return 'default';
    }

    /**
     * Get chunk size for export.
     *
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000;
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
     * Map a row to export format.
     *
     * Override this method to customize row output.
     *
     * @param mixed $item
     * @return array<mixed>
     */
    public function map(mixed $item): array
    {
        if (is_array($item)) {
            return array_values($item);
        }

        if (is_object($item)) {
            if (method_exists($item, 'toArray')) {
                return array_values($item->toArray());
            }
            return array_values((array) $item);
        }

        return [$item];
    }

    /**
     * Check if this export has a title.
     *
     * @return bool
     */
    public function hasTitle(): bool
    {
        return $this instanceof WithTitleInterface;
    }

    /**
     * Check if this export should be queued.
     *
     * @return bool
     */
    public function shouldQueue(): bool
    {
        return $this instanceof ShouldQueueInterface;
    }

    /**
     * Check if this export has multiple sheets.
     *
     * @return bool
     */
    public function hasMultipleSheets(): bool
    {
        return $this instanceof WithMultipleSheetsInterface;
    }

    /**
     * Check if this export has events.
     *
     * @return bool
     */
    public function hasEvents(): bool
    {
        return $this instanceof WithEventsInterface;
    }

    /**
     * Check if this export tracks progress.
     *
     * @return bool
     */
    public function tracksProgress(): bool
    {
        return $this instanceof WithProgressInterface;
    }
}
