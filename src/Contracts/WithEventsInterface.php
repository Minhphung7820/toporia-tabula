<?php

declare(strict_types=1);

namespace Toporia\Tabula\Contracts;

/**
 * Interface WithEventsInterface
 *
 * Implement this interface to register event handlers for import/export lifecycle.
 */
interface WithEventsInterface
{
    /**
     * Get the registered event handlers.
     *
     * @return array<string, callable> Event name => handler callable
     *
     * @example
     * return [
     *     'beforeImport' => fn() => Log::info('Starting import'),
     *     'afterImport' => fn($result) => Log::info("Imported {$result->totalRows} rows"),
     *     'onError' => fn($e, $row) => Log::error("Error on row: {$e->getMessage()}"),
     *     'beforeChunk' => fn($chunkIndex) => Log::info("Processing chunk {$chunkIndex}"),
     *     'afterChunk' => fn($chunkIndex, $count) => Log::info("Processed {$count} rows"),
     * ];
     */
    public function registerEvents(): array;
}
