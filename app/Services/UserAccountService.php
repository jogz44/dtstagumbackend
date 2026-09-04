<?php

namespace App\Services;

use App\Enums\UserCreationSource;
use App\Models\Office;
use App\Models\Division;
use App\Models\PmisEmployee;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Transliterator;

class UserAccountService
{
    public function __construct(
        protected UserInitialPasswordService $passwordService,
        protected AuditService $auditService,
    ) {}

    public function generateUsername(string $firstName, string $lastName): string
    {
        $firstInitial = mb_substr($this->normalizeUsernamePart($firstName), 0, 1);
        $surname = $this->normalizeUsernamePart($lastName);

        if ($firstInitial === '') {
            $firstInitial = 'u';
        }

        if ($surname === '') {
            $surname = 'user';
        }

        $base = "{$firstInitial}.{$surname}";
        $username = $base;
        $suffix = 2;

        while (User::withTrashed()->whereRaw('LOWER(username) = ?', [strtolower($username)])->exists()) {
            $username = $base.$suffix;
            $suffix++;
        }

        return $username;
    }

    public function normalizeUsernamePart(string $value): string
    {
        $value = trim(mb_strtolower($value));

        if (class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator) {
                $transliterated = $transliterator->transliterate($value);
                if ($transliterated !== false) {
                    $value = $transliterated;
                }
            }
        }

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }

    public function createUser(array $validated, ?User $actor, Request $request): User
    {
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                return DB::transaction(function () use ($validated, $actor, $request) {
                    $isManual = empty($validated['pmis_employee_id']);

                    if ($isManual) {
                        $firstName = trim($validated['first_name']);
                        $lastName = trim($validated['last_name']);
                        $middleName = isset($validated['middle_name']) ? trim($validated['middle_name']) : null;
                        $birthdateStr = $validated['birthdate'];

                        $employeeId = null;
                        $position = $validated['position'] ?? null;
                        $employmentStatus = $validated['employment_status'] ?? null;
                        $pmisEmployeeId = null;
                        $officeId = $validated['office_id'] ?? null;
                        $divisionId = $validated['division_id'] ?? null;
                        $creationSource = UserCreationSource::MANUAL->value;
                    } else {
                        $employee = PmisEmployee::findOrFail($validated['pmis_employee_id']);

                        if ($employee->user()->withTrashed()->exists()) {
                            abort(response()->json([
                                'message' => 'This PMIS employee already has a linked portal account.',
                                'errors' => ['pmis_employee_id' => ['This PMIS employee already has a linked portal account.']],
                            ], 422));
                        }

                        if ($employee->birth_date === null) {
                            abort(response()->json([
                                'message' => 'This employee does not have a verified birth date in PMIS and cannot be issued the standard temporary password.',
                                'errors' => ['pmis_employee_id' => ['This employee does not have a verified birth date in PMIS.']],
                            ], 422));
                        }

                        $firstName = $employee->first_name ?: '';
                        $lastName = $employee->last_name ?: '';
                        $middleName = null;
                        $birthdateStr = $employee->birth_date->format('Y-m-d');
                        $employeeId = $employee->pmis_no;
                        $position = $employee->designation;
                        $employmentStatus = $employee->employment_status;
                        $pmisEmployeeId = $employee->id;
                        $officeId = $validated['office_id'] ?? $this->resolveOfficeId($employee->office);
                        $divisionId = $validated['division_id'] ?? $this->resolveDivisionId($officeId, $employee->division);
                        $creationSource = UserCreationSource::PMIS->value;
                    }

                    $candidateUsername = isset($validated['username']) && filled($validated['username'])
                        ? trim($validated['username'])
                        : $this->generateUsername($firstName, $lastName);

                    $temporaryPassword = $this->passwordService->fromBirthdate($birthdateStr);

                    $userData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'middle_name' => $middleName,
                        'username' => $candidateUsername,
                        'password' => Hash::make($temporaryPassword),
                        'must_change_password' => true,
                        'is_active' => filter_var($validated['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                        'birthdate' => $birthdateStr,
                        'employee_id' => $employeeId,
                        'pmis_employee_id' => $pmisEmployeeId,
                        'office_id' => $officeId,
                        'division_id' => $divisionId,
                        'barangay_id' => $validated['barangay_id'] ?? null,
                        'email' => isset($validated['email']) && filled($validated['email']) ? trim($validated['email']) : null,
                        'position' => $position,
                        'employment_status' => $employmentStatus,
                        'creation_source' => $creationSource,
                    ];

                    $user = User::create($userData);

                    $roleName = $validated['role'] ?? $this->defaultRoleName();
                    if ($roleName) {
                        $user->syncRoles([$roleName]);
                    }

                    $user->refresh();

                    $this->auditService->accountHistory(
                        $user,
                        $actor,
                        'account.created',
                        'User account was created.',
                        [],
                        $request,
                    );

                    $activityDescription = $isManual
                        ? "Created portal account for {$user->first_name} {$user->last_name} manually."
                        : "Created portal account for PMIS employee {$user->first_name} {$user->last_name} (PMIS No. {$employeeId}).";

                    $this->auditService->activity(
                        $actor,
                        'account.created',
                        'User Management',
                        $activityDescription,
                        $request,
                        [
                            'resource_type' => 'User',
                            'resource_id' => $user->id,
                            'creation_source' => $creationSource,
                        ],
                    );

                    return $user;
                });
            } catch (QueryException $e) {
                // If unique constraint on username collided concurrently, retry username generation
                if ($attempt < $maxRetries && str_contains(strtolower($e->getMessage()), 'username')) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Failed to generate a unique username after multiple attempts.');
    }

    private function defaultRoleName(): ?string
    {
        if (DB::table('roles')->where('name', RoleNames::RECEIVER)->exists()) {
            return RoleNames::RECEIVER;
        }

        if (DB::table('roles')->where('name', RoleNames::SUPERVISOR)->exists()) {
            return RoleNames::SUPERVISOR;
        }

        return null;
    }

    private function resolveOfficeId(?string $pmisOffice): ?string
    {
        if ($pmisOffice === null || trim($pmisOffice) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($pmisOffice)));

        $office = Office::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->orWhereRaw('LOWER(TRIM(short_name)) = ?', [$normalized])
            ->first();

        return $office?->id;
    }

    private function resolveDivisionId(?string $officeId, ?string $pmisDivision): ?string
    {
        if ($officeId === null || $pmisDivision === null || trim($pmisDivision) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($pmisDivision)));

        $division = Division::query()
            ->where('office_id', $officeId)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(short_name)) = ?', [$normalized]);
            })
            ->first();

        return $division?->id;
    }
}
