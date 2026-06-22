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
    $row = DB::connection('mysql_secondary')
        ->selectOne("
            SELECT *
            FROM bpar_i_person
            WHERE bpar_i_person_id = 1
        ");
 
    print_r($row);
     

};