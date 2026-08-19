<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        // Only attempt to synchronize with pmis if explicitly requested or if no offices exist locally
        if ((request()->has('sync') || Office::where('is_active', true)->count() === 0) && $this->checkPmisOnline()) {
            try {
                // Fetch only divisions and office columns from the pmis2003.vwActive view
                $pmisRecords = DB::connection('pmis')
                    ->table('vwActive')
                    ->select('office', 'divisions')
                    ->distinct()
                    ->get();

                // Synchronize the offices and divisions to the local lgudts database
                foreach ($pmisRecords as $record) {
                    $officeName = $record->office ? trim((string) $record->office) : null;
                    $divisionName = $record->divisions ? trim((string) $record->divisions) : null;

                    // Synchronize the Office
                    if ($officeName) {
                        $officeCode = Str::upper(Str::limit(Str::slug($officeName), 40, '')) . '-' . substr(md5($officeName), 0, 5);

                        $office = Office::withTrashed()->updateOrCreate(
                            ['name' => $officeName],
                            [
                                'code' => $officeCode,
                                'short_name' => $officeName,
                                'is_active' => true,
                                'deleted_at' => null,
                            ]
                        );

                        // Synchronize the Division under this Office (if present)
                        if ($divisionName) {
                            $divisionCode = Str::upper(Str::limit(Str::slug($divisionName), 40, '')) . '-' . substr(md5($divisionName), 0, 5);

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
            } catch (\Exception $e) {
                // Log warning but let the application continue using whatever is already local
                Log::warning("Could not synchronize offices/divisions from pmis2003.vwActive: " . $e->getMessage());
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
        if (!$officeName) {
            return response()->json([]);
        }

        $users = [];

        if ($this->checkPmisOnline()) {
            try {
                $pmisUsers = DB::connection('pmis')
                    ->table('vwActive')
                    ->where('office', $officeName)
                    ->select('Name1')
                    ->distinct()
                    ->orderBy('Name1')
                    ->get();

                $users = $pmisUsers->pluck('Name1')->filter()->values()->all();
            } catch (\Exception $e) {
                Log::warning("Could not query office users from pmis2003.vwActive: " . $e->getMessage());
            }
        }

        // If pmis query failed or returned empty, fallback to local users of the office
        if (empty($users)) {
            $office = Office::where('name', $officeName)->first();
            if ($office) {
                // Find users in local database belonging to this office
                $localUsers = \App\Models\User::where('office_id', $office->id)
                    ->where('is_active', true)
                    ->get();

                if ($localUsers->isNotEmpty()) {
                    $users = $localUsers->map(fn($u) => $u->first_name . ' ' . $u->last_name)->all();
                }
            }
        }

        // Final mock fallback if still empty
        if (empty($users)) {
            $users = [
                'Jacob Gabriel A. Dumdum',
                'Bonna May Malnegro',
                'Ariane Celocia',
                'Joan D. Lanciola',
                'Alvin C. Fernandez'
            ];
        }

        return response()->json($users);
    }

    private function checkPmisOnline(): bool
    {
        $host = config('database.connections.pmis.host');
        $port = config('database.connections.pmis.port', 1433);

        if (!$host || $host === '127.0.0.1') {
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
