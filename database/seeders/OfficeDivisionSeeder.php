<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Office;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfficeDivisionSeeder extends Seeder
{
    /**
     * Connection name pointing at the pmis2003 database.
     * Add this in config/database.php, e.g.:
     *
     * 'pmis2003' => [
     *     'driver'   => 'sqlsrv', // or 'mysql' — depends on what pmis2003 actually runs on
     *     'host'     => env('PMIS2003_DB_HOST', '127.0.0.1'),
     *     'port'     => env('PMIS2003_DB_PORT', '1433'),
     *     'database' => env('PMIS2003_DB_DATABASE', 'pmis2003'),
     *     'username' => env('PMIS2003_DB_USERNAME', ''),
     *     'password' => env('PMIS2003_DB_PASSWORD', ''),
     * ],
     *
     * And in .env:
     * PMIS2003_DB_HOST=...
     * PMIS2003_DB_DATABASE=pmis2003
     * PMIS2003_DB_USERNAME=...
     * PMIS2003_DB_PASSWORD=...
     */
    protected string $connection = 'pmis';

    protected string $view = 'vwActive';

    public function run(): void
    {
        $rows = collect();

        try {
            $rows = DB::connection($this->connection)
                ->table($this->view)
                ->select('office', 'divisions')
                ->distinct()
                ->get();
        } catch (\Exception $e) {
            $this->command?->warn("Could not query {$this->connection}.{$this->view}: " . $e->getMessage());
            $this->command?->info("Seeding fallback mock offices and divisions...");

            $rows = collect([
                (object) ['office' => 'Office of the City Mayor', 'divisions' => 'Administrative Support'],
                (object) ['office' => 'Office of the City Mayor', 'divisions' => 'Legal Section'],
                (object) ['office' => 'City Information and Communications Technology Management Office', 'divisions' => 'Systems Development Division'],
                (object) ['office' => 'City Information and Communications Technology Management Office', 'divisions' => 'Network & Security Division'],
                (object) ['office' => 'Human Resource Management Office', 'divisions' => 'Recruitment & Placement'],
            ]);
        }

        if ($rows->isEmpty()) {
            $this->command?->warn("No rows to sync.");
            return;
        }

        foreach ($rows as $row) {
            $officeName = $row->office ? trim((string) $row->office) : null;
            $divisionName = $row->divisions ? trim((string) $row->divisions) : null;

            if (!$officeName) {
                continue;
            }

            $officeCode = Str::upper(Str::limit(Str::slug($officeName), 40, '')) . '-' . substr(md5($officeName), 0, 5);

            $office = Office::updateOrCreate(
                ['name' => $officeName],
                [
                    'code'       => $officeCode,
                    'short_name' => Str::limit($officeName, 100, ''),
                    'is_active'  => true,
                ]
            );

            if ($divisionName) {
                $divisionCode = Str::upper(Str::limit(Str::slug($divisionName), 40, '')) . '-' . substr(md5($divisionName), 0, 5);

                Division::updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'name'      => $divisionName,
                    ],
                    [
                        'code'       => $divisionCode,
                        'short_name' => Str::limit($divisionName, 100, ''),
                        'is_active'  => true,
                    ]
                );
            }
        }

        $this->command?->info("Offices and divisions synced successfully.");
    }
}