<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Update dummy docs with priority levels
$updates = [
    ['tracking_number' => 'HRMO-2026-07-000001', 'priority_level' => 'High'],
    ['tracking_number' => 'HRMO-2026-07-000002', 'priority_level' => 'Normal'],
    ['tracking_number' => 'HRMO-2026-07-000003', 'priority_level' => 'Low'],
    ['tracking_number' => 'HRMO-2026-07-000004', 'priority_level' => 'High'],
    ['tracking_number' => 'HRMO-2026-07-000005', 'priority_level' => 'Normal'],
    ['tracking_number' => 'HRMO-2026-07-000006', 'priority_level' => 'Low'],
];

foreach ($updates as $u) {
    DB::table('documents')
        ->where('tracking_number', $u['tracking_number'])
        ->update(['priority_level' => $u['priority_level']]);
    echo "  Updated {$u['tracking_number']} → {$u['priority_level']}\n";
}

echo "Done!\n";
