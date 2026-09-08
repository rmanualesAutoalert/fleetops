<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:regenerate-daily-branch-summaries')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer();
