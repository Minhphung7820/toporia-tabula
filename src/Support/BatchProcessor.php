<?php

declare(strict_types=1);

namespace Toporia\Tabula\Support;

/**
 * Class BatchProcessor
 *
 * Utility for processing data in batches with memory optimization.
 * Essential for handling large datasets efficiently.
 */
final class BatchProcessor
{
    /**
     * @var array<mixed> Current batch buffer
     */
    private array $buffer = [];

    private int $batchSize;

    /**
     * @var callable Processor callback
     */
    private $processor;

    private int $processedCount = 0;
    private int $batchCount = 0;

    /**
     * @param int $batchSize Items per batch
     * @param callable(array<mixed>, int): void $processor Batch processor callback
     */
    public function __construct(int $batchSize, callable $processor)
    {
        $this->batchSize = $batchSize;
        $this->processor = $processor;
    }

    /**
     * Create a new batch processor.
     *
     * @param int $batchSize
     * @param callable(array<mixed>, int): void $processor
     * @return self
     */
    public static function make(int $batchSize, callable $processor): self
    {
        return new self($batchSize, $processor);
    }

    /**
     * Add an item to the batch.
     *
     * @param mixed $item
     * @return self
     */
    public function add(mixed $item): self
    {
        $this->buffer[] = $item;
        $this->processedCount++;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }

        // Memory optimization
        MemoryOptimizer::tick();

        return $this;
    }

    /**
     * Add multiple items.
     *
     * @param iterable<mixed> $items
     * @return self
     */
    public function addMany(iterable $items): self
    {
        foreach ($items as $item) {
            $this->add($item);
        }
        return $this;
    }

    /**
     * Flush the current batch.
     *
     * @return self
     */
    public function flush(): self
    {
        if (empty($this->buffer)) {
            return $this;
        }

        ($this->processor)($this->buffer, $this->batchCount);
        $this->batchCount++;
        $this->buffer = [];

        // Force garbage collection after flush
        MemoryOptimizer::collectGarbage();

        return $this;
    }

    /**
     * Get the number of items processed.
     *
     * @return int
     */
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    /**
     * Get the number of batches processed.
     *
     * @return int
     */
    public function getBatchCount(): int
    {
        return $this->batchCount;
    }

    /**
     * Get the current buffer size.
     *
     * @return int
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Process an iterable in batches.
     *
     * @param iterable<mixed> $items Items to process
     * @param int $batchSize Batch size
     * @param callable(array<mixed>, int): void $processor Batch processor
     * @return int Total items processed
     */
    public static function process(iterable $items, int $batchSize, callable $processor): int
    {
        $batch = self::make($batchSize, $processor);

        foreach ($items as $item) {
            $batch->add($item);
        }

        $batch->flush();

        return $batch->getProcessedCount();
    }

    /**
     * Destructor - flush remaining items.
     */
    public function __destruct()
    {
        $this->flush();
    }
}
