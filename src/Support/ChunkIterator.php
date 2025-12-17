<?php

declare(strict_types=1);

namespace Toporia\Tabula\Support;

/**
 * Class ChunkIterator
 *
 * Memory-efficient iterator that processes data in chunks.
 * Essential for handling large datasets without memory issues.
 *
 * Performance characteristics:
 * - Memory: O(chunkSize) - only holds one chunk at a time
 * - Time: O(n) where n = total items
 */
final class ChunkIterator implements \IteratorAggregate
{
    /**
     * @param iterable<mixed> $source Source data
     * @param int $chunkSize Items per chunk
     */
    public function __construct(
        private iterable $source,
        private int $chunkSize = 1000,
    ) {
    }

    /**
     * Iterate over chunks.
     *
     * @return \Generator<int, array<mixed>>
     */
    public function getIterator(): \Generator
    {
        $chunk = [];
        $chunkIndex = 0;

        foreach ($this->source as $item) {
            $chunk[] = $item;

            if (count($chunk) >= $this->chunkSize) {
                yield $chunkIndex => $chunk;
                $chunk = [];
                $chunkIndex++;
            }
        }

        // Yield remaining items
        if (!empty($chunk)) {
            yield $chunkIndex => $chunk;
        }
    }

    /**
     * Process each chunk with a callback.
     *
     * @param callable(array<mixed>, int): void $callback
     * @return int Number of chunks processed
     */
    public function each(callable $callback): int
    {
        $count = 0;

        foreach ($this->getIterator() as $chunkIndex => $chunk) {
            $callback($chunk, $chunkIndex);
            $count++;
        }

        return $count;
    }

    /**
     * Create from generator.
     *
     * @param \Generator<mixed> $generator
     * @param int $chunkSize
     * @return self
     */
    public static function fromGenerator(\Generator $generator, int $chunkSize = 1000): self
    {
        return new self($generator, $chunkSize);
    }

    /**
     * Create from array.
     *
     * @param array<mixed> $array
     * @param int $chunkSize
     * @return self
     */
    public static function fromArray(array $array, int $chunkSize = 1000): self
    {
        return new self($array, $chunkSize);
    }
}
