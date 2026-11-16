<?php

return [
    'sheet_id' => env('ASET_SHEET_ID'),
    'sheet_gid_1a' => env('ASET_SHEET_GID_1A'),
    'sheet_gid_1b' => env('ASET_SHEET_GID_1B'),
    'period' => env('ASET_SYNC_PERIOD'),
    'target_month' => env('ASET_TARGET_MONTH', 'Oktober 2026'),
    'timeout' => (int) env('ASET_HTTP_TIMEOUT', 15),
    'retries' => (int) env('ASET_HTTP_RETRIES', 2),
    'schedule_enabled' => filter_var(env('ASET_SYNC_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'schedule_cron' => (string) (env('ASET_SYNC_SCHEDULE_CRON') ?: '0 6 * * *'),
];
