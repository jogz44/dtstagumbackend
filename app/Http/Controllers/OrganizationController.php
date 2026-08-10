<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            // Fetch all offices and divisions from the PMIS SSMS database (read-only)
            $pmisOffices = DB::connection('pmis')->table('offices')->get();
            $pmisDivisions = DB::connection('pmis')->table('divisions')->get();

            // Synchronize offices to the local lgudts database
            foreach ($pmisOffices as $office) {
                // Handle both lowercase and uppercase property names commonly found in SSMS/SQL Server
                $officeId = $office->id ?? $office->ID ?? null;
                $officeName = $office->name ?? $office->NAME ?? null;
                $officeCode = $office->code ?? $office->CODE ?? null;
                $officeShortName = $office->short_name ?? $office->SHORT_NAME ?? null;
                $officeActive = $office->is_active ?? $office->IS_ACTIVE ?? true;

                if ($officeId && $officeName) {
                    Office::withTrashed()->updateOrCreate(
                        ['id' => (string) $officeId],
                        [
                            'code' => $officeCode,
                            'name' => $officeName,
                            'short_name' => $officeShortName,
                            'is_active' => (bool) $officeActive,
                            'deleted_at' => null,
                        ]
                    );
                }
            }

            // Synchronize divisions to the local lgudts database
            foreach ($pmisDivisions as $division) {
                $divisionId = $division->id ?? $division->ID ?? null;
                $divisionOfficeId = $division->office_id ?? $division->OFFICE_ID ?? null;
                $divisionName = $division->name ?? $division->NAME ?? null;
                $divisionCode = $division->code ?? $division->CODE ?? null;
                $divisionShortName = $division->short_name ?? $division->SHORT_NAME ?? null;
                $divisionActive = $division->is_active ?? $division->IS_ACTIVE ?? true;

                if ($divisionId && $divisionOfficeId && $divisionName) {
                    Division::withTrashed()->updateOrCreate(
                        ['id' => (string) $divisionId],
                        [
                            'office_id' => (string) $divisionOfficeId,
                            'code' => $divisionCode,
                            'name' => $divisionName,
                            'short_name' => $divisionShortName,
                            'is_active' => (bool) $divisionActive,
                            'deleted_at' => null,
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Log warning but let the application continue using whatever is already local
            Log::warning("Could not synchronize offices/divisions from pmis2003: " . $e->getMessage());
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