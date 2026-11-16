<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('aset:sync')
    ->cron((string) (config('asset.schedule_cron', '0 6 * * *') ?: '0 6 * * *'))
    ->withoutOverlapping(15)
    ->when(fn (): bool => (bool) config('asset.schedule_enabled', false));
