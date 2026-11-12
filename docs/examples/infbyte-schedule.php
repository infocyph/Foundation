<?php

declare(strict_types=1);

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('reports:daily')
        ->key('reports.daily')
        ->dailyAt('02:00')
        ->timezone('UTC')
        ->onOneServer()
        ->withoutOverlap();
};
