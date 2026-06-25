<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// No /scripts dashboard. One-off scripts are run by the deploy pipeline:
// `php artisan scripts:run-pending` executes everything in scripts/pending/
// and files each to scripts/done/ (success) or scripts/failed/ (failure).
