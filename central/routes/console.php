<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('discover:prune-snapshots')->dailyAt('03:00');
