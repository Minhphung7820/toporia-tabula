<?php

declare(strict_types=1);

namespace Toporia\Tabula\Support;

/**
 * Class MemoryOptimizer
 *
 * Utilities for memory optimization during large imports/exports.
 */
final class MemoryOptimizer
{
    private static int $gcInterval = 1000;
    private static int $rowCounter = 0;
    private static bool $enabled = true;

    /**
     * Enable memory optimization.
     *
     * @return void
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable memory optimization.
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Set garbage collection interval.
     *
     * @param int $rows Number of rows between GC cycles
     * @return void
     */
    public static function setGcInterval(int $rows): void
    {
        self::$gcInterval = max(100, $rows);
    }

    /**
     * Tick - called for each row processed.
     * Triggers garbage collection periodically.
     *
     * @return void
     */
    public static function tick(): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$rowCounter++;

        if (self::$rowCounter % self::$gcInterval === 0) {
            self::collectGarbage();
        }
    }

    /**
     * Force garbage collection.
     *
     * @return void
     */
    public static function collectGarbage(): void
    {
        if (!gc_enabled()) {
            gc_enable();
        }

        gc_collect_cycles();
    }

    /**
     * Get current memory usage.
     *
     * @param bool $real Use real memory size
     * @return int Memory usage in bytes
     */
    public static function getMemoryUsage(bool $real = false): int
    {
        return memory_get_usage($real);
    }

    /**
     * Get peak memory usage.
     *
     * @param bool $real Use real memory size
     * @return int Peak memory usage in bytes
     */
    public static function getPeakMemoryUsage(bool $real = false): int
    {
        return memory_get_peak_usage($real);
    }

    /**
     * Get memory usage as human-readable string.
     *
     * @param bool $real Use real memory size
     * @return string Formatted memory usage
     */
    public static function getMemoryUsageFormatted(bool $real = false): string
    {
        return self::formatBytes(self::getMemoryUsage($real));
    }

    /**
     * Get peak memory usage as human-readable string.
     *
     * @param bool $real Use real memory size
     * @return string Formatted peak memory usage
     */
    public static function getPeakMemoryUsageFormatted(bool $real = false): string
    {
        return self::formatBytes(self::getPeakMemoryUsage($real));
    }

    /**
     * Format bytes to human-readable string.
     *
     * @param int $bytes
     * @return string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Execute a callback with memory tracking.
     *
     * @param callable $callback
     * @return array{result: mixed, memory_used: int, peak_memory: int, duration: float}
     */
    public static function track(callable $callback): array
    {
        $startMemory = self::getMemoryUsage(true);
        $startTime = microtime(true);

        $result = $callback();

        $endTime = microtime(true);
        $endMemory = self::getMemoryUsage(true);

        return [
            'result' => $result,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => self::getPeakMemoryUsage(true),
            'duration' => $endTime - $startTime,
        ];
    }

    /**
     * Reset the row counter.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$rowCounter = 0;
    }

    /**
     * Optimize PHP settings for large operations.
     *
     * @param int $timeLimit Time limit in seconds (0 = unlimited)
     * @param string $memoryLimit Memory limit (e.g., '512M', '1G')
     * @return void
     */
    public static function optimize(int $timeLimit = 0, string $memoryLimit = '512M'): void
    {
        // Disable time limit for large operations
        set_time_limit($timeLimit);

        // Increase memory limit
        ini_set('memory_limit', $memoryLimit);

        // Enable garbage collection
        gc_enable();

        // Disable output buffering for streaming
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
