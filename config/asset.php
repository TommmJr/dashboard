<?php

return [
    'sheet_id' => env('ASET_SHEET_ID'),
    'sheet_gid_1a' => env('ASET_SHEET_GID_1A'),
    'sheet_gid_1b' => env('ASET_SHEET_GID_1B'),
    'period' => env('ASET_SYNC_PERIOD'),
    'target_month' => env('ASET_TARGET_MONTH', 'Oktober 2026'),
    'timeout' => (int) env('ASET_HTTP_TIMEOUT', 15),
    'retries' => (int) env('ASET_HTTP_RETRIES', 2),
];
