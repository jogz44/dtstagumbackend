<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $result = DB::connection('pmis')->select('SELECT TOP 1 * FROM vwActive');
    if ($result) {
        foreach ((array) $result[0] as $key => $val) {
            echo $key.' => '.$val."\n";
        }
    } else {
        echo "No rows found.\n";
    }
} catch (Exception $e) {
    echo 'FAILED: '.$e->getMessage()."\n";
}
