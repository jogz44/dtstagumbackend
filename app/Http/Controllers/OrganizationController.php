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

        $offices = Office::query()
            ->with(['divisions' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $offices]);
    }
}