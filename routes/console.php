<?php

use Illuminate\Support\Facades\Schedule;

// Refresh the local copy of the LezWatch.TV catalogue.
Schedule::command('lwtv:sync')->cron('0 */6 * * *')->withoutOverlapping(30);
