<?php

namespace App\Http\Controllers;

use App\Models\PmisEmployee;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PmisEmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser->hasRole(RoleNames::ADMIN) && ! $authUser->hasRole('admin') && ! $authUser->can('users.manage') && ! $authUser->can('users.view')) {
            abort(403, 'You are not authorized to view the PMIS employee directory.');
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'office' => ['nullable', 'string', 'max:150'],
            'employment_status' => ['nullable', 'string', 'max:100'],
            'available_only' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = PmisEmployee::query()
            ->withExists('user')
            ->with(['user:id,pmis_employee_id,username,is_active']);

        if ($request->boolean('available_only')) {
            $query->whereDoesntHave('user');
        }

        if (isset($validated['search']) && filled($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('pmis_no', 'LIKE', "%{$search}%")
                    ->orWhere('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('middle_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'LIKE', "%{$search}%")
                    ->orWhere(DB::raw("CONCAT(first_name, ' ', middle_name, ' ', last_name)"), 'LIKE', "%{$search}%")
                    ->orWhere('designation', 'LIKE', "%{$search}%")
                    ->orWhere('office', 'LIKE', "%{$search}%");
            });
        }

        if (isset($validated['office']) && filled($validated['office'])) {
            $query->where('office', $validated['office']);
        }

        if (isset($validated['employment_status']) && filled($validated['employment_status'])) {
            $query->where('employment_status', $validated['employment_status']);
        }

        $perPage = $this->clampedPageSize((int) ($validated['per_page'] ?? 25));

        $employees = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->orderBy('pmis_no')
            ->paginate($perPage);

        $data = collect($employees->items())->map(function (PmisEmployee $employee) {
            $fullName = collect([$employee->first_name, $employee->middle_name, $employee->last_name])
                ->filter(fn ($part) => filled($part))
                ->map(fn ($part) => trim((string) $part))
                ->implode(' ');

            $payload = [
                'id' => $employee->id,
                'pmis_no' => $employee->pmis_no,
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name' => $employee->last_name,
                'full_name' => $fullName,
                'birth_date' => $employee->birth_date?->format('Y-m-d'),
                'designation' => $employee->designation,
                'designation_code' => $employee->designation_code,
                'office' => $employee->office,
                'employment_status' => $employee->employment_status,
                'telephone' => $employee->telephone,
                'has_portal_account' => (bool) $employee->user_exists,
            ];

            if ($employee->user) {
                $payload['portal_account'] = [
                    'id' => $employee->user->id,
                    'username' => $employee->user->username,
                    'is_active' => (bool) $employee->user->is_active,
                ];
            }

            return $payload;
        })->values();

        $pagination = [
            'current_page' => $employees->currentPage(),
            'per_page' => $employees->perPage(),
            'total' => $employees->total(),
            'last_page' => $employees->lastPage(),
        ];

        return response()->json([
            'data' => $data,
            'pagination' => $pagination,
            'meta' => $pagination,
        ]);
    }

    private function clampedPageSize(int $requestedPageSize): int
    {
        return match (true) {
            $requestedPageSize <= 25 => 25,
            $requestedPageSize <= 50 => 50,
            default => 100,
        };
    }
}
