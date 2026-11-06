<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('uploads:expire')->daily()->withoutOverlapping()->onOneServer();
Schedule::command('submissions:purge-deleted')->daily()->withoutOverlapping()->onOneServer();
