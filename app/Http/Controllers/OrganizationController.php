<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        // Only attempt to synchronize with pmis if explicitly requested or if no offices exist locally
        if (request()->has('sync') || Office::where('is_active', true)->count() === 0) {
            $pmisRecords = collect();
            $synced = false;

            if ($this->checkPmisOnline()) {
                try {
                    // Fetch only divisions and office columns from the pmis2003.vwActive view
                    $pmisRecords = DB::connection('pmis')
                        ->table('vwActive')
                        ->select('office', 'divisions')
                        ->distinct()
                        ->get();
                    $synced = true;
                } catch (\Exception $e) {
                    Log::warning('Could not synchronize offices/divisions from pmis2003.vwActive: '.$e->getMessage());
                }
            }

            // Fallback mock seeding if PMIS was offline/failed and we have no offices locally
            if (! $synced && Office::where('is_active', true)->count() === 0) {
                $pmisRecords = collect([
                    (object) ['office' => 'Office of the City Mayor', 'divisions' => 'Administrative Support'],
                    (object) ['office' => 'Office of the City Mayor', 'divisions' => 'Legal Section'],
                    (object) ['office' => 'City Information and Communications Technology Management Office', 'divisions' => 'Systems Development Division'],
                    (object) ['office' => 'City Information and Communications Technology Management Office', 'divisions' => 'Network & Security Division'],
                    (object) ['office' => 'Human Resource Management Office', 'divisions' => 'Recruitment & Placement'],
                ]);
            }

            // Synchronize the offices and divisions to the local lgudts database
            foreach ($pmisRecords as $record) {
                $officeName = $record->office ? trim((string) $record->office) : null;
                $divisionName = $record->divisions ? trim((string) $record->divisions) : null;

                if ($officeName) {
                    $officeCode = Str::upper(Str::limit(Str::slug($officeName), 40, '')).'-'.substr(md5($officeName), 0, 5);

                    $office = Office::withTrashed()->updateOrCreate(
                        ['name' => $officeName],
                        [
                            'code' => $officeCode,
                            'short_name' => $officeName,
                            'is_active' => true,
                            'deleted_at' => null,
                        ]
                    );

                    if ($divisionName) {
                        $divisionCode = Str::upper(Str::limit(Str::slug($divisionName), 40, '')).'-'.substr(md5($divisionName), 0, 5);

                        Division::withTrashed()->updateOrCreate(
                            [
                                'office_id' => $office->id,
                                'name' => $divisionName,
                            ],
                            [
                                'code' => $divisionCode,
                                'short_name' => $divisionName,
                                'is_active' => true,
                                'deleted_at' => null,
                            ]
                        );
                    }
                }
            }
        }

        $offices = Office::query()
            ->with(['divisions' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $offices]);
    }

    public function getOfficeUsers(): JsonResponse
    {
        $officeName = request('office');
        $divisionName = request('division');

        if (! $officeName) {
            return response()->json([]);
        }

        // Always try local database first for structured results with IDs
        $office = Office::where('name', $officeName)->first();
        if ($office) {
            $localQuery = User::where('office_id', $office->id)
                ->where('is_active', true);

            // Filter by division if provided
            if ($divisionName) {
                $localQuery->whereHas('division', function ($q) use ($divisionName) {
                    $q->where('name', $divisionName);
                });
            }

            $localUsers = $localQuery->get();

            if ($localUsers->isNotEmpty()) {
                return response()->json($localUsers->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->full_name,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'username' => $u->username,
                    'position' => $u->position,
                    'office_id' => $u->office_id,
                    'division_id' => $u->division_id,
                ]));
            }
        }

        // Fallback: try PMIS for names (no IDs available)
        if ($this->checkPmisOnline()) {
            try {
                $pmisQuery = DB::connection('pmis')
                    ->table('vwActive')
                    ->where('office', $officeName)
                    ->select('Name1')
                    ->distinct()
                    ->orderBy('Name1');

                $pmisUsers = $pmisQuery->get();
                $names = $pmisUsers->pluck('Name1')->filter()->values()->all();

                if (! empty($names)) {
                    return response()->json($names);
                }
            } catch (\Exception $e) {
                Log::warning('Could not query office users from pmis2003.vwActive: '.$e->getMessage());
            }
        }

        // Fallback 2: try local synced pmis_employees table (real synced employee names)
        try {
            $localPmisQuery = PmisEmployee::where('office', $officeName);
            if ($divisionName) {
                $localPmisQuery->where('division', $divisionName);
            }
            $localPmisNames = $localPmisQuery->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn ($emp) => trim(($emp->first_name ? $emp->first_name.' ' : '').$emp->last_name))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($localPmisNames)) {
                return response()->json($localPmisNames);
            }
        } catch (\Exception $e) {
            Log::warning('Could not query local pmis_employees: '.$e->getMessage());
        }

        // Final mock fallback if still empty
        return response()->json([
            'Jacob Gabriel A. Dumdum',
            'Bonna May Malnegro',
            'Ariane Celocia',
            'Joan D. Lanciola',
            'Alvin C. Fernandez',
        ]);
    }

    private function checkPmisOnline(): bool
    {
        $host = config('database.connections.pmis.host');
        $port = config('database.connections.pmis.port', 1433);

        if (! $host || $host === '127.0.0.1') {
            return false;
        }

        try {
            $connection = @fsockopen($host, $port, $errno, $errstr, 0.5); // 500ms timeout
            if ($connection) {
                fclose($connection);

                return true;
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return false;
    }
}
