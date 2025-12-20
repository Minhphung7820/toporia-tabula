<?php

declare(strict_types=1);

namespace Toporia\Tabula\Imports;

use Toporia\Tabula\Contracts\ImportableInterface;
use Toporia\Tabula\Contracts\WithChunkReadingInterface;
use Toporia\Tabula\Contracts\WithParallelInterface;
use Toporia\Tabula\Contracts\WithProgressInterface;
use Toporia\Tabula\Exceptions\ImportException;
use Toporia\Tabula\Support\ImportResult;

/**
 * Parallel Importer
 *
 * High-performance parallel CSV importer with multiple driver support.
 * Each worker reads every Nth line from the file (round-robin distribution).
 *
 * Architecture:
 * 1. Main process spawns N worker processes/forks
 * 2. Each worker reads every Nth line (worker 0: lines 0,N,2N..., worker 1: lines 1,N+1,2N+1...)
 * 3. Each worker has its own database connection (shared-nothing via DB::reconnect())
 * 4. Results are sent back via IPC and aggregated in main process
 *
 * Driver support:
 * - fork: Best performance. Uses pcntl_fork(). Requires ext-pcntl (Linux/macOS).
 *         Closures/mappers work directly without serialization.
 * - process: Cross-platform. Uses proc_open() to spawn PHP processes.
 *            Mapper support via inline code generation from source.
 * - sync: Sequential execution. Fallback when parallel not supported.
 */
final class ParallelImporter
{
    private string $driver = 'process';

    /**
     * Running worker information (for fork driver).
     *
     * @var array<int, array{pid: int, socket: resource, workerIndex: int, startTime: float}>
     */
    private array $runningWorkers = [];

    /**
     * Collected results from workers.
     *
     * @var array<int, array{total: int, success: int, failed: int}>
     */
    private array $results = [];

    /**
     * Progress callback for real-time updates.
     *
     * @var callable|null
     */
    private $progressCallback = null;

    /**
     * Total rows to import (for progress calculation).
     */
    private int $totalRows = 0;

    /**
     * Model class for counting inserted rows.
     */
    private string $modelClass = '';

    /**
     * Initial row count before import (for progress calculation).
     */
    private int $initialRowCount = 0;

    /**
     * Last time progress was reported (for throttling).
     */
    private float $lastProgressTime = 0;

    /**
     * Last reported progress percentage (for avoiding duplicate updates).
     */
    private int $lastProgressPercent = -1;

    /**
     * Set concurrency driver.
     *
     * @param string $driver Driver name (fork, process, sync)
     * @return self
     */
    public function driver(string $driver): self
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * Check if fork driver is supported.
     */
    public static function isForkSupported(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill')
            && PHP_SAPI === 'cli';
    }

    /**
     * Check if process driver is supported.
     */
    public static function isProcessSupported(): bool
    {
        return function_exists('proc_open') && PHP_SAPI === 'cli';
    }

    /**
     * Count total data rows in CSV file (excluding header).
     *
     * @param string $filePath Path to CSV file
     * @return int Number of data rows
     */
    private function countRows(string $filePath): int
    {
        $count = 0;
        $handle = @fopen($filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        // Skip header row
        @fgetcsv($handle, 0, ',', '"', '');

        // Count data rows
        while (@fgetcsv($handle, 0, ',', '"', '') !== false) {
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Check and report progress by counting database rows.
     *
     * Uses throttling to avoid excessive database queries (max once per 500ms).
     * Reports progress only when percentage changes by at least 2%.
     *
     * @return void
     */
    private function checkAndReportProgress(): void
    {
        // Skip if no progress callback or model class
        if ($this->progressCallback === null || $this->modelClass === '' || $this->totalRows === 0) {
            return;
        }

        // Throttle progress checks (max once per 500ms)
        $now = microtime(true);
        if ($now - $this->lastProgressTime < 0.5) {
            return;
        }
        $this->lastProgressTime = $now;

        // Count current rows in database
        try {
            $currentCount = $this->modelClass::count();
            $insertedRows = $currentCount - $this->initialRowCount;
            $percentage = (int) (($insertedRows / $this->totalRows) * 100);

            // Only report if progress changed by at least 2%
            if ($percentage > $this->lastProgressPercent + 1) {
                $this->lastProgressPercent = $percentage;
                ($this->progressCallback)(min($insertedRows, $this->totalRows), $this->totalRows);
            }
        } catch (\Throwable $e) {
            // Ignore count errors - progress is optional
        }
    }

    /**
     * Import CSV file using parallel workers.
     *
     * @param ImportableInterface&WithChunkReadingInterface&WithParallelInterface $import
     * @param string $filePath
     * @return ImportResult
     */
    public function import(
        ImportableInterface&WithChunkReadingInterface&WithParallelInterface $import,
        string $filePath
    ): ImportResult {
        if (!file_exists($filePath)) {
            throw ImportException::fileNotFound($filePath);
        }

        // Extract progress callback if available
        if ($import instanceof WithProgressInterface) {
            // Try to get the progress callback from ToModelImport
            if (method_exists($import, 'hasProgressCallback') && $import->hasProgressCallback()) {
                $this->progressCallback = function (int $current, int $total) use ($import): void {
                    $percentage = $total > 0 ? ($current / $total) * 100 : 0;
                    $import->onProgress($current, $total, $percentage);
                };
            }
        }

        // Count total rows for progress tracking
        if ($this->progressCallback !== null) {
            $this->totalRows = $this->countRows($filePath);

            // Get model class for counting inserted rows
            if (method_exists($import, 'model')) {
                $this->modelClass = $import->model();
                // Store initial row count
                try {
                    $this->initialRowCount = $this->modelClass::count();
                } catch (\Throwable $e) {
                    $this->initialRowCount = 0;
                }
            }

            // Report initial progress
            ($this->progressCallback)(0, $this->totalRows);
        }

        // Auto-select driver if not available
        $driver = $this->selectDriver();

        return match ($driver) {
            'fork' => $this->importWithFork($import, $filePath),
            'process' => $this->importWithProcess($import, $filePath),
            default => $this->importSync($import, $filePath),
        };
    }

    /**
     * Select best available driver.
     */
    private function selectDriver(): string
    {
        if ($this->driver === 'fork' && self::isForkSupported()) {
            return 'fork';
        }

        if ($this->driver === 'process' && self::isProcessSupported()) {
            return 'process';
        }

        if ($this->driver === 'sync') {
            return 'sync';
        }

        // Fallback: try fork first, then process, then sync
        if (self::isForkSupported()) {
            return 'fork';
        }

        if (self::isProcessSupported()) {
            return 'process';
        }

        return 'sync';
    }

    /**
     * Import using fork driver (pcntl_fork).
     * Best performance, supports mapper/closure directly.
     */
    private function importWithFork(
        ImportableInterface&WithChunkReadingInterface&WithParallelInterface $import,
        string $filePath
    ): ImportResult {
        $workers = $import->workers();
        $startTime = microtime(true);

        // Read headers from first line
        $headers = $this->readHeaders($filePath);

        // Get import configuration
        $modelClass = $import->model();
        $batchSize = $import->batchSize();
        $uniqueBy = method_exists($import, 'getUniqueBy') ? $import->getUniqueBy() : null;
        $upsertColumns = method_exists($import, 'getUpsertColumns') ? $import->getUpsertColumns() : null;
        $mapper = method_exists($import, 'getMapper') ? $import->getMapper() : null;

        // Reset state
        $this->runningWorkers = [];
        $this->results = [];

        // Register signal handlers
        $this->registerSignalHandlers();

        // Fork workers
        for ($i = 0; $i < $workers; $i++) {
            $this->forkWorker(
                $i,
                $workers,
                $filePath,
                $headers,
                $modelClass,
                $batchSize,
                $uniqueBy,
                $upsertColumns,
                $mapper
            );
        }

        // Wait for all workers and collect results
        $this->waitForForkWorkers();

        // Aggregate results
        return $this->aggregateResults(microtime(true) - $startTime);
    }

    /**
     * Import using process driver (proc_open).
     * Cross-platform, supports mapper via inline code generation.
     */
    private function importWithProcess(
        ImportableInterface&WithChunkReadingInterface&WithParallelInterface $import,
        string $filePath
    ): ImportResult {
        $workers = $import->workers();
        $startTime = microtime(true);

        // Read headers from first line
        $headers = $this->readHeaders($filePath);

        // Get import configuration
        $modelClass = $import->model();
        $batchSize = $import->batchSize();
        $uniqueBy = method_exists($import, 'getUniqueBy') ? $import->getUniqueBy() : null;
        $upsertColumns = method_exists($import, 'getUpsertColumns') ? $import->getUpsertColumns() : null;

        // Get mapper source code for inline execution
        $mapperCode = $this->extractMapperCode($import);

        // Reset state
        $this->results = [];

        // Spawn process workers
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[$i] = $this->spawnProcessWorker(
                $i,
                $workers,
                $filePath,
                $headers,
                $modelClass,
                $batchSize,
                $uniqueBy,
                $upsertColumns,
                $mapperCode
            );
        }

        // Wait for all processes and collect results
        $this->waitForProcessWorkers($processes);

        // Aggregate results
        return $this->aggregateResults(microtime(true) - $startTime);
    }

    /**
     * Import synchronously (fallback).
     */
    private function importSync(
        ImportableInterface&WithChunkReadingInterface&WithParallelInterface $import,
        string $filePath
    ): ImportResult {
        $startTime = microtime(true);

        // Read headers
        $headers = $this->readHeaders($filePath);

        // Get import configuration
        $modelClass = $import->model();
        $batchSize = $import->batchSize();
        $uniqueBy = method_exists($import, 'getUniqueBy') ? $import->getUniqueBy() : null;
        $upsertColumns = method_exists($import, 'getUpsertColumns') ? $import->getUpsertColumns() : null;
        $mapper = method_exists($import, 'getMapper') ? $import->getMapper() : null;

        // Process all rows in single thread
        $workerResult = $this->processWorker(
            0,
            1,
            $filePath,
            $headers,
            $modelClass,
            $batchSize,
            $uniqueBy,
            $upsertColumns,
            $mapper
        );

        $this->results = [0 => $workerResult];

        return $this->aggregateResults(microtime(true) - $startTime);
    }

    /**
     * Extract mapper code from import for use in process driver.
     * Returns PHP code that can be inlined in the worker script.
     */
    private function extractMapperCode(ImportableInterface $import): ?string
    {
        // Try to get mapper from ToModelImport or similar
        if (!method_exists($import, 'getMapper')) {
            return null;
        }

        $mapper = $import->getMapper();
        if ($mapper === null) {
            return null;
        }

        // If it's a Closure, extract the source code using reflection
        if ($mapper instanceof \Closure) {
            try {
                $reflection = new \ReflectionFunction($mapper);
                $filename = $reflection->getFileName();
                $startLine = $reflection->getStartLine();
                $endLine = $reflection->getEndLine();

                if ($filename && $startLine && $endLine && file_exists($filename)) {
                    $source = file($filename);
                    $length = $endLine - $startLine + 1;
                    $closureCode = implode('', array_slice($source, $startLine - 1, $length));

                    // Extract arrow function body: fn($row) => [...]
                    if (preg_match('/fn\s*\([^)]*\)\s*=>\s*(.+)/s', $closureCode, $matches)) {
                        $body = trim($matches[1]);
                        // Remove trailing semicolons, commas, parentheses that are part of outer code
                        $body = rtrim($body, ",;)");
                        // Balance brackets
                        $body = $this->balanceBrackets($body);
                        return $body;
                    }

                    // Try traditional closure syntax: function($row) { return [...]; }
                    if (preg_match('/function\s*\([^)]*\)\s*(?:use\s*\([^)]*\))?\s*\{(.+)\}/s', $closureCode, $matches)) {
                        return trim($matches[1]);
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to return null
            }
        }

        return null;
    }

    /**
     * Balance brackets in extracted code.
     */
    private function balanceBrackets(string $code): string
    {
        $openBrackets = 0;
        $openParens = 0;
        $result = '';

        for ($i = 0; $i < strlen($code); $i++) {
            $char = $code[$i];

            if ($char === '[') {
                $openBrackets++;
            } elseif ($char === ']') {
                $openBrackets--;
                if ($openBrackets < 0) {
                    break;
                }
            } elseif ($char === '(') {
                $openParens++;
            } elseif ($char === ')') {
                $openParens--;
                if ($openParens < 0) {
                    break;
                }
            }

            $result .= $char;

            // Stop after the main array is closed
            if ($openBrackets === 0 && $openParens === 0 && $char === ']') {
                break;
            }
        }

        return $result;
    }

    /**
     * Fork a single worker process (fork driver).
     */
    private function forkWorker(
        int $workerIndex,
        int $totalWorkers,
        string $filePath,
        array $headers,
        string $modelClass,
        int $batchSize,
        ?array $uniqueBy,
        ?array $upsertColumns,
        ?callable $mapper
    ): void {
        // Create socket pair for IPC
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new \RuntimeException('Failed to create socket pair for worker ' . $workerIndex);
        }

        [$parentSocket, $childSocket] = $sockets;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            throw new \RuntimeException('pcntl_fork() failed for worker ' . $workerIndex);
        }

        if ($pid === 0) {
            // CHILD PROCESS
            fclose($parentSocket);
            $this->executeForkWorker(
                $childSocket,
                $workerIndex,
                $totalWorkers,
                $filePath,
                $headers,
                $modelClass,
                $batchSize,
                $uniqueBy,
                $upsertColumns,
                $mapper
            );
            // Never returns
        }

        // PARENT PROCESS
        fclose($childSocket);

        $this->runningWorkers[$pid] = [
            'pid' => $pid,
            'socket' => $parentSocket,
            'workerIndex' => $workerIndex,
            'startTime' => microtime(true),
        ];
    }

    /**
     * Execute worker in forked child process.
     *
     * @param resource $socket
     */
    private function executeForkWorker(
        $socket,
        int $workerIndex,
        int $totalWorkers,
        string $filePath,
        array $headers,
        string $modelClass,
        int $batchSize,
        ?array $uniqueBy,
        ?array $upsertColumns,
        ?callable $mapper
    ): never {
        // Reconnect database in child process and disable FK checks
        if (function_exists('DB') && function_exists('config')) {
            try {
                $defaultConnection = config('database.default', 'mysql');

                // Reconnect database
                \DB()->reconnect();

                // Get the actual connection and disable FK checks
                $conn = \DB()->connection($defaultConnection);
                $conn->statement('SET FOREIGN_KEY_CHECKS=0');

                // Set the connection for ORM models so they use the same connection
                if (class_exists(\Toporia\Framework\Database\ORM\Model::class)) {
                    \Toporia\Framework\Database\ORM\Model::setConnection($conn->getConnection());
                }
            } catch (\Throwable $e) {
                // Ignore DB errors - import may still work
            }
        }

        $result = $this->processWorker(
            $workerIndex,
            $totalWorkers,
            $filePath,
            $headers,
            $modelClass,
            $batchSize,
            $uniqueBy,
            $upsertColumns,
            $mapper
        );

        // Write result to socket
        $payload = serialize($result);
        fwrite($socket, $payload);
        fflush($socket);
        fclose($socket);

        // Exit child process
        exit(0);
    }

    /**
     * Spawn a process worker (process driver).
     *
     * @return array{process: resource, pipes: array, workerIndex: int, scriptPath: string, stdout: string, stderr: string}
     */
    private function spawnProcessWorker(
        int $workerIndex,
        int $totalWorkers,
        string $filePath,
        array $headers,
        string $modelClass,
        int $batchSize,
        ?array $uniqueBy,
        ?array $upsertColumns,
        ?string $mapperCode
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // Write PHP script to temp file
        $scriptPath = $this->createWorkerScript(
            $workerIndex,
            $totalWorkers,
            $filePath,
            $headers,
            $modelClass,
            $batchSize,
            $uniqueBy,
            $upsertColumns,
            $mapperCode
        );

        // Spawn process
        $process = proc_open(
            [PHP_BINARY, $scriptPath],
            $descriptors,
            $pipes,
            base_path()
        );

        if (!is_resource($process)) {
            @unlink($scriptPath);
            throw new \RuntimeException('Failed to spawn process for worker ' . $workerIndex);
        }

        // Set pipes to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Close stdin
        fclose($pipes[0]);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'workerIndex' => $workerIndex,
            'scriptPath' => $scriptPath,
            'stdout' => '',
            'stderr' => '',
        ];
    }

    /**
     * Create worker PHP script file.
     */
    private function createWorkerScript(
        int $workerIndex,
        int $totalWorkers,
        string $filePath,
        array $headers,
        string $modelClass,
        int $batchSize,
        ?array $uniqueBy,
        ?array $upsertColumns,
        ?string $mapperCode
    ): string {
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE);
        $uniqueByJson = $uniqueBy !== null ? json_encode($uniqueBy) : 'null';
        $upsertColumnsJson = $upsertColumns !== null ? json_encode($upsertColumns) : 'null';
        $filePathEscaped = addcslashes($filePath, "'\\");
        $modelClassEscaped = addcslashes($modelClass, "'\\");

        // Build mapper function code
        $mapperFunction = 'null';
        if ($mapperCode !== null) {
            $mapperFunction = "fn(\$row) => {$mapperCode}";
        }

        // Worker script uses output buffering to capture ALL output from bootstrap
        // Then outputs ONLY the serialized result at the end with a marker
        $code = <<<'WORKER_SCRIPT'
<?php
declare(strict_types=1);

// Start output buffering BEFORE anything else to capture ALL output
ob_start();

// Suppress ALL errors to prevent any output
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Custom error handler to prevent any output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    return true; // Suppress all errors
});

// Custom exception handler
set_exception_handler(function($e) {
    // Discard all buffered output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Output error result with marker
    echo "___TABULA_RESULT___" . serialize(['total' => 0, 'success' => 0, 'failed' => 0]);
    exit(0);
});

// Shutdown handler to ensure clean output
register_shutdown_function(function() {
    // Check for fatal errors
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo "___TABULA_RESULT___" . serialize(['total' => 0, 'success' => 0, 'failed' => 0]);
    }
});

try {
    require_once 'vendor/autoload.php';
    require_once 'bootstrap/app.php';
} catch (Throwable $e) {
    // Ignore bootstrap errors, continue with import
}

// Discard any output from bootstrap
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Reconnect database, disable FK checks, and set up Model connection
try {
    if (function_exists('DB') && function_exists('config')) {
        $defaultConnection = config('database.default', 'mysql');

        // Reconnect database
        DB()->reconnect();

        // Get the actual connection and disable FK checks
        $conn = DB()->connection($defaultConnection);
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');

        // CRITICAL: Set the connection for ORM models so they use the same connection
        // where FK checks are disabled
        if (class_exists(\Toporia\Framework\Database\ORM\Model::class)) {
            \Toporia\Framework\Database\ORM\Model::setConnection($conn->getConnection());
        }
    }
} catch (Throwable $e) {
    // Ignore DB errors - import may still work for inserts
}

WORKER_SCRIPT;

        $code .= "\n\$filePath = '{$filePathEscaped}';\n";
        $code .= "\$headers = json_decode('{$headersJson}', true);\n";
        $code .= "\$modelClass = '{$modelClassEscaped}';\n";
        $code .= "\$batchSize = {$batchSize};\n";
        $code .= "\$uniqueBy = json_decode('{$uniqueByJson}', true);\n";
        $code .= "\$upsertColumns = json_decode('{$upsertColumnsJson}', true);\n";
        $code .= "\$workerIndex = {$workerIndex};\n";
        $code .= "\$totalWorkers = {$totalWorkers};\n";
        $code .= "\$mapper = {$mapperFunction};\n";

        $code .= <<<'WORKER_SCRIPT'

$total = 0;
$success = 0;
$failed = 0;

$handle = @fopen($filePath, 'r');
if ($handle === false) {
    echo "___TABULA_RESULT___" . serialize(['total' => 0, 'success' => 0, 'failed' => 0]);
    exit(0);
}

// Skip header row (use fgetcsv to handle multiline properly)
@fgetcsv($handle, 0, ',', '"', '');

$currentRecord = 0;
$batch = [];
$headerCount = count($headers);

// Use fgetcsv instead of fgets to properly handle multiline values in CSV
while (($row = @fgetcsv($handle, 0, ',', '"', '')) !== false) {
    // Round-robin distribution: each worker processes every Nth record
    if ($currentRecord++ % $totalWorkers !== $workerIndex) {
        continue;
    }

    if (empty($row) || $row[0] === null) {
        continue;
    }

    $total++;

    $rowCount = count($row);
    if ($rowCount < $headerCount) {
        $row = array_pad($row, $headerCount, null);
    } elseif ($rowCount > $headerCount) {
        $row = array_slice($row, 0, $headerCount);
    }

    $csvRow = @array_combine($headers, $row);
    if ($csvRow === false) {
        $failed++;
        continue;
    }

    if ($mapper !== null) {
        try {
            $csvRow = $mapper($csvRow);
        } catch (Throwable $e) {
            $failed++;
            continue;
        }
        if ($csvRow === null) {
            continue;
        }
    }

    $batch[] = $csvRow;

    if (count($batch) >= $batchSize) {
        try {
            if ($uniqueBy !== null && method_exists($modelClass, 'upsert')) {
                $modelClass::upsert($batch, $uniqueBy, $upsertColumns);
            } elseif (method_exists($modelClass, 'insert')) {
                $modelClass::insert($batch);
            }
            $success += count($batch);
        } catch (Throwable $e) {
            $failed += count($batch);
        }
        $batch = [];
    }
}

if (!empty($batch)) {
    try {
        if ($uniqueBy !== null && method_exists($modelClass, 'upsert')) {
            $modelClass::upsert($batch, $uniqueBy, $upsertColumns);
        } elseif (method_exists($modelClass, 'insert')) {
            $modelClass::insert($batch);
        }
        $success += count($batch);
    } catch (Throwable $e) {
        $failed += count($batch);
    }
}

@fclose($handle);

// Output result with marker - this is the ONLY output that should appear
echo "___TABULA_RESULT___" . serialize(['total' => $total, 'success' => $success, 'failed' => $failed]);
exit(0);
WORKER_SCRIPT;

        // Write to temp file
        $scriptPath = sys_get_temp_dir() . '/tabula_worker_' . $workerIndex . '_' . uniqid() . '.php';
        file_put_contents($scriptPath, $code);

        return $scriptPath;
    }

    /**
     * Process rows assigned to this worker.
     *
     * @return array{total: int, success: int, failed: int}
     */
    private function processWorker(
        int $workerIndex,
        int $totalWorkers,
        string $filePath,
        array $headers,
        string $modelClass,
        int $batchSize,
        ?array $uniqueBy,
        ?array $upsertColumns,
        ?callable $mapper
    ): array {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        // Skip header row using fgetcsv to handle multiline properly
        fgetcsv($handle, 0, ',', '"', '');

        $currentRecord = 0;
        $total = 0;
        $success = 0;
        $failed = 0;
        $batch = [];
        $headerCount = count($headers);

        // Use fgetcsv instead of fgets to properly handle multiline values in CSV
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            // Round-robin distribution: each worker only processes its assigned records
            if ($currentRecord++ % $totalWorkers !== $workerIndex) {
                continue;
            }

            if (empty($row) || $row[0] === null) {
                continue;
            }

            $total++;

            // Normalize row length
            $rowCount = count($row);
            if ($rowCount < $headerCount) {
                $row = array_pad($row, $headerCount, null);
            } elseif ($rowCount > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }

            $csvRow = array_combine($headers, $row);

            // Apply mapper if exists
            if ($mapper !== null) {
                try {
                    $csvRow = $mapper($csvRow);
                } catch (\Throwable $e) {
                    $failed++;
                    continue;
                }
                if ($csvRow === null) {
                    continue;
                }
            }

            $batch[] = $csvRow;

            // Flush batch when full
            if (count($batch) >= $batchSize) {
                $inserted = $this->insertBatch($batch, $modelClass, $uniqueBy, $upsertColumns);
                $success += $inserted;
                $failed += count($batch) - $inserted;
                $batch = [];
            }
        }

        // Flush remaining batch
        if (!empty($batch)) {
            $inserted = $this->insertBatch($batch, $modelClass, $uniqueBy, $upsertColumns);
            $success += $inserted;
            $failed += count($batch) - $inserted;
        }

        fclose($handle);

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    /**
     * Insert a batch of rows into the database.
     *
     * @param array<array<string, mixed>> $batch
     * @return int Number of rows inserted
     */
    private function insertBatch(
        array $batch,
        string $modelClass,
        ?array $uniqueBy,
        ?array $upsertColumns
    ): int {
        try {
            if ($uniqueBy !== null && method_exists($modelClass, 'upsert')) {
                $modelClass::upsert($batch, $uniqueBy, $upsertColumns);
            } elseif (method_exists($modelClass, 'insert')) {
                $modelClass::insert($batch);
            } else {
                foreach ($batch as $data) {
                    $modelClass::create($data);
                }
            }
            return count($batch);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Wait for all fork workers and collect their results.
     */
    private function waitForForkWorkers(): void
    {
        while (!empty($this->runningWorkers)) {
            // Dispatch signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            foreach ($this->runningWorkers as $pid => $workerInfo) {
                // Non-blocking wait
                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result === $pid) {
                    // Worker finished
                    $this->collectForkWorkerResult($pid, $workerInfo);
                    unset($this->runningWorkers[$pid]);
                } elseif ($result === -1) {
                    // Error or already reaped
                    unset($this->runningWorkers[$pid]);
                }
                // result === 0 means still running
            }

            // Check and report progress while workers are running
            $this->checkAndReportProgress();

            // Small delay to prevent CPU spinning
            if (!empty($this->runningWorkers)) {
                usleep(5000); // 5ms
            }
        }

        // Report final progress
        if ($this->progressCallback !== null && $this->totalRows > 0) {
            ($this->progressCallback)($this->totalRows, $this->totalRows);
        }
    }

    /**
     * Wait for all process workers and collect their results.
     *
     * @param array<int, array{process: resource, pipes: array, workerIndex: int, scriptPath: string, stdout: string, stderr: string}> $processes
     */
    private function waitForProcessWorkers(array &$processes): void
    {
        while (!empty($processes)) {
            foreach ($processes as $idx => &$info) {
                // Read available output
                $stdout = stream_get_contents($info['pipes'][1]);
                $stderr = stream_get_contents($info['pipes'][2]);

                if ($stdout !== false && $stdout !== '') {
                    $info['stdout'] .= $stdout;
                }

                if ($stderr !== false && $stderr !== '') {
                    $info['stderr'] .= $stderr;
                }

                // Check process status
                $status = proc_get_status($info['process']);

                if (!$status['running']) {
                    // Read remaining output
                    stream_set_blocking($info['pipes'][1], true);
                    $finalStdout = stream_get_contents($info['pipes'][1]);
                    if ($finalStdout !== false) {
                        $info['stdout'] .= $finalStdout;
                    }

                    // Close pipes and process
                    fclose($info['pipes'][1]);
                    fclose($info['pipes'][2]);
                    proc_close($info['process']);

                    // Clean up temp script
                    if (isset($info['scriptPath']) && file_exists($info['scriptPath'])) {
                        @unlink($info['scriptPath']);
                    }

                    // Parse result
                    $this->collectProcessWorkerResult($info);
                    unset($processes[$idx]);
                }
            }
            unset($info);

            // Check and report progress while workers are running
            $this->checkAndReportProgress();

            // Small delay
            if (!empty($processes)) {
                usleep(10000); // 10ms
            }
        }

        // Report final progress
        if ($this->progressCallback !== null && $this->totalRows > 0) {
            ($this->progressCallback)($this->totalRows, $this->totalRows);
        }
    }

    /**
     * Collect result from a finished fork worker.
     *
     * @param array{pid: int, socket: resource, workerIndex: int, startTime: float} $workerInfo
     */
    private function collectForkWorkerResult(int $pid, array $workerInfo): void
    {
        $socket = $workerInfo['socket'];
        $workerIndex = $workerInfo['workerIndex'];

        // Read result from socket
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 5);

        $data = stream_get_contents($socket);
        fclose($socket);

        if ($data === '' || $data === false) {
            $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
            return;
        }

        try {
            $result = unserialize($data);
            if (is_array($result) && isset($result['total'])) {
                $this->results[$workerIndex] = $result;
            } else {
                $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
            }
        } catch (\Throwable $e) {
            $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
        }
    }

    /**
     * Collect result from a finished process worker.
     *
     * @param array{process: resource, pipes: array, workerIndex: int, scriptPath: string, stdout: string, stderr: string} $info
     */
    private function collectProcessWorkerResult(array $info): void
    {
        $workerIndex = $info['workerIndex'];
        $output = $info['stdout'];

        if ($output === '') {
            $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
            return;
        }

        // Find our result marker - everything after it is the serialized result
        $marker = '___TABULA_RESULT___';
        $markerPos = strpos($output, $marker);

        if ($markerPos === false) {
            // No marker found - worker failed or had unexpected output
            $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
            return;
        }

        // Extract serialized data after marker
        $serialized = substr($output, $markerPos + strlen($marker));

        try {
            $result = unserialize($serialized);
            if (is_array($result) && isset($result['total'])) {
                $this->results[$workerIndex] = $result;
            } else {
                $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
            }
        } catch (\Throwable $e) {
            $this->results[$workerIndex] = ['total' => 0, 'success' => 0, 'failed' => 0];
        }
    }

    /**
     * Read headers from CSV file.
     *
     * @return array<string>
     */
    private function readHeaders(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);

        if ($headers === false) {
            return [];
        }

        return array_map('trim', $headers);
    }

    /**
     * Aggregate results from all workers.
     */
    private function aggregateResults(float $duration): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->results as $workerResult) {
            $result->incrementTotal($workerResult['total']);
            $result->incrementSuccess($workerResult['success']);

            if ($workerResult['failed'] > 0) {
                for ($i = 0; $i < $workerResult['failed']; $i++) {
                    $result->incrementFailed();
                }
            }
        }

        $result->setDuration($duration);

        return $result;
    }

    /**
     * Register signal handlers for graceful shutdown (fork driver).
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signal): void {
            $this->killAllForkWorkers();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGQUIT, $handler);

        pcntl_async_signals(true);
    }

    /**
     * Kill all running fork workers.
     */
    private function killAllForkWorkers(): void
    {
        foreach ($this->runningWorkers as $pid => $workerInfo) {
            posix_kill($pid, SIGTERM);
        }

        // Grace period
        usleep(100000); // 100ms

        // Force kill remaining
        foreach ($this->runningWorkers as $pid => $workerInfo) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status, WNOHANG);

            if (isset($workerInfo['socket']) && is_resource($workerInfo['socket'])) {
                fclose($workerInfo['socket']);
            }
        }

        $this->runningWorkers = [];
    }
}
