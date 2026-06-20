<?php

use Illuminate\Support\Facades\DB;

/**
 * Sample one-off script that reads a value from the database.
 *
 * Must return a closure. It runs inside the booted Laravel app, so Eloquent,
 * facades and config are all available. The closure receives the artisan
 * command instance ($cmd). Plain echo output is captured and shown on the
 * /scripts dashboard.
 *
 * This one counts the users table — a safe, read-only query that proves the DB
 * connection works without touching data (and without failing on an empty table).
 */

return function ($cmd) {
    $row = DB::selectOne("SELECT 'hello world' AS message");
    echo $row->message . "\n";
    $cmd->info($row->message);
};
