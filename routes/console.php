<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('booking:sync-provider-identifiers')
    ->dailyAt('00:30')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:scrape-daily')
    ->dailyAt('01:00')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:collect-environmental-data today')
    ->dailyAt('00:45')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:collect-environmental-data yesterday --finalize')
    ->dailyAt('00:50')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:score-latest')
    ->dailyAt('02:00')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:send-hot-alerts')
    ->dailyAt('02:15')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:send-weekly-digest')
    ->sundays()
    ->at('07:00')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ai-reviews:prune')
    ->monthly()
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ai-parsing:prune')
    ->monthly()
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ai-reviews:monitor')
    ->everyFiveMinutes()
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping(10)
    ->onOneServer();
