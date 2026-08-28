<?php

namespace App\Services;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    public const ACCOUNT_FIELDS = [
        'username',
        'first_name',
        'last_name',
        'employee_id',
        'birthdate',
        'office_id',
        'division_id',
        'position',
        'email',
        'is_active',
        'role',
        'permission_overrides',
    ];

    public function accountHistory(
        User $subjectUser,
        ?User $actor,
        string $eventType,
        string $summary,
        array $changes = [],
        ?Request $request = null,
    ): AccountHistoryEvent {
        return AccountHistoryEvent::create([
            'subject_user_id' => $subjectUser->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'event_type' => $eventType,
            'summary' => $summary,
            'changes' => $this->allowlistedChanges($changes),
            'office_id' => $subjectUser->office_id,
            'division_id' => $subjectUser->division_id,
            'request_id' => $request?->headers->get('X-Request-Id'),
            'source_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    public function activity(
        ?User $actor,
        string $type,
        string $module,
        string $description,
        ?Request $request = null,
        array $context = [],
    ): ActivityEvent {
        return ActivityEvent::create([
            'actor_user_id' => $actor?->id,
            'activity_type' => $type,
            'module' => $module,
            'resource_type' => $context['resource_type'] ?? null,
            'resource_id' => isset($context['resource_id']) ? (string) $context['resource_id'] : null,
            'description' => $description,
            'outcome' => $context['outcome'] ?? 'success',
            'office_id' => $actor?->office_id ?? ($context['office_id'] ?? null),
            'division_id' => $actor?->division_id ?? ($context['division_id'] ?? null),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->headers->get('X-Request-Id'),
            'session_id' => $context['session_id'] ?? null,
            'occurred_at' => now(),
        ]);
    }

    public function userSnapshot(User $user): array
    {
        return [
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'employee_id' => $user->employee_id,
            'birthdate' => $user->birthdate?->toDateString(),
            'office_id' => $user->office_id,
            'division_id' => $user->division_id,
            'position' => $user->position,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role' => $user->getRoleNames()->first(),
        ];
    }

    public function diffSnapshots(array $before, array $after): array
    {
        $changes = [];

        foreach (self::ACCOUNT_FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[$field] = [
                    'before' => $before[$field] ?? null,
                    'after' => $after[$field] ?? null,
                ];
            }
        }

        return $changes;
    }

    private function allowlistedChanges(array $changes): array
    {
        return collect($changes)
            ->only(self::ACCOUNT_FIELDS)
            ->map(fn ($change) => [
                'before' => $change['before'] ?? null,
                'after' => $change['after'] ?? null,
            ])
            ->all();
    }
}
