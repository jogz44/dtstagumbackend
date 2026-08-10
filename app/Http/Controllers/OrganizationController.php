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
            // Fetch all records from the pmis2003.vwActive table view
            $pmisRecords = DB::connection('pmis')->table('vwActive')->get();

            // Synchronize the offices and divisions to the local lgudts database
            foreach ($pmisRecords as $record) {
                // Extract Office fields (supporting multiple column naming conventions in SSMS)
                $officeId = $record->office_id ?? $record->OfficeID ?? $record->OfficeId ?? $record->dept_id ?? $record->DeptID ?? $record->id ?? $record->ID ?? null;
                $officeName = $record->office_name ?? $record->OfficeName ?? $record->Office ?? $record->dept_name ?? $record->DeptName ?? $record->name ?? $record->NAME ?? null;
                $officeCode = $record->office_code ?? $record->OfficeCode ?? $record->code ?? $record->CODE ?? $record->short_name ?? null;

                // Extract Division fields (divisions can be nullable if some offices don't have divisions)
                $divisionId = $record->division_id ?? $record->DivisionID ?? $record->DivisionId ?? null;
                $divisionName = $record->division_name ?? $record->DivisionName ?? $record->Division ?? null;
                $divisionCode = $record->division_code ?? $record->DivisionCode ?? null;

                // Synchronize the Office
                if ($officeId && $officeName) {
                    Office::withTrashed()->updateOrCreate(
                        ['id' => (string) $officeId],
                        [
                            'code' => $officeCode,
                            'name' => $officeName,
                            'short_name' => $officeCode,
                            'is_active' => true,
                            'deleted_at' => null,
                        ]
                    );

                    // Synchronize the Division under this Office (if present and distinct from the office ID)
                    if ($divisionId && $divisionName && (string)$divisionId !== (string)$officeId) {
                        Division::withTrashed()->updateOrCreate(
                            ['id' => (string) $divisionId],
                            [
                                'office_id' => (string) $officeId,
                                'code' => $divisionCode,
                                'name' => $divisionName,
                                'short_name' => $divisionCode,
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