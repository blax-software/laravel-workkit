<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Settings
    |--------------------------------------------------------------------------
    |
    | Used by workkit:db:backup, workkit:db:restore and
    | workkit:db:prune-backups. The default `path` is storage_path('backups')
    | so backups live alongside the rest of the app's storage. `retention_days`
    | is the threshold workkit:db:prune-backups uses by default — anything
    | older than that gets deleted on the next prune run.
    |
    */
    'backup' => [
        'path' => env('WORKKIT_BACKUP_PATH'),  // null → storage_path('backups')
        'retention_days' => (int) env('WORKKIT_BACKUP_RETENTION_DAYS', 30),
    ],
];
