<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Import Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for import operations.
    |
    */

    'import' => [
        // Default chunk size for reading
        'chunk_size' => 1000,

        // Default batch size for database inserts
        'batch_size' => 500,

        // Skip invalid rows instead of failing
        'skip_invalid_rows' => false,

        // Maximum errors before stopping (null = unlimited)
        'max_errors' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Export Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for export operations.
    |
    */

    'export' => [
        // Default chunk size for writing
        'chunk_size' => 1000,

        // Default file format
        'default_format' => 'xlsx',

        // Include UTF-8 BOM for CSV (helps Excel)
        'csv_include_bom' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Settings for queued imports and exports.
    |
    */

    'queue' => [
        // Default queue name
        'name' => 'default',

        // Job timeout in seconds
        'timeout' => 3600,

        // Maximum retry attempts
        'max_attempts' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Storage
    |--------------------------------------------------------------------------
    |
    | Where to store temporary files during processing.
    |
    */

    'temp_path' => sys_get_temp_dir(),
];
