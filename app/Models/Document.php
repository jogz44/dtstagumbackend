<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tracking_number
 * @property string|null $created_by
 * @property string|null $origin_sender
 * @property string|null $sender_name
 * @property string|null $document_type
 * @property string|null $priority_level
 * @property string|null $description
 * @property string|null $remarks
 * @property bool $is_confidential
 * @property bool $self_assign
 * @property bool $send_to_all
 * @property bool $is_internal
 * @property string|null $office_assignment
 * @property string|null $division_assignment
 * @property string|null $assigned_user
 * @property bool $is_read_only
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Document extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tracking_number', 'created_by', 'origin_sender', 'sender_name', 'document_type',
        'priority_level', 'description', 'remarks', 'is_confidential', 'self_assign',
        'send_to_all', 'is_internal', 'office_assignment', 'division_assignment',
        'assigned_user', 'is_read_only', 'status', 'due_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_confidential' => 'boolean',
            'self_assign' => 'boolean',
            'send_to_all' => 'boolean',
            'is_internal' => 'boolean',
            'is_read_only' => 'boolean',
            'due_date' => 'date:Y-m-d',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DocumentHistory::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DocumentMessage::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class);
    }

    public function scopeForMyDocuments($query, User $user)
    {
        $officeName = $user->office?->name;

        return $query->where('status', '!=', 'archived')
            ->where(function ($q) use ($user, $officeName) {
                // Created by the user
                $q->where('created_by', $user->id);

                // Or creator is in the same office (outgoing or internal)
                if ($user->office_id) {
                    $q->orWhereHas('creator', function ($q2) use ($user) {
                        $q2->where('office_id', $user->office_id);
                    });
                }

                // Or assigned to their office
                if ($officeName) {
                    $q->orWhere('office_assignment', $officeName)
                      ->orWhereHas('histories', function ($q3) use ($officeName) {
                          $q3->where('to_office', $officeName);
                      });
                }
            });
    }

    public function scopeForRegistry($query, User $user)
    {
        return $query->where('is_confidential', false)
            ->where('status', '!=', 'archived')
            ->where(function ($q) use ($user) {
                $officeName = $user->office?->name;

                if ($user->office_id) {
                    $q->whereHas('creator', function ($q2) use ($user) {
                        $q2->where('office_id', $user->office_id);
                    });
                }

                if ($officeName) {
                    $q->orWhere('office_assignment', $officeName)
                      ->orWhere('send_to_all', true)
                      ->orWhereHas('histories', function ($q3) use ($officeName) {
                          $q3->where('action', 'SHARED')
                             ->where('to_office', $officeName);
                      });
                } else {
                    $q->orWhere('send_to_all', true);
                }
            });
    }

    public function scopeForIncoming($query, User $user)
    {
        $officeName = $user->office?->name;
        $divisionName = $user->division?->name;
        $fullName = $user->full_name;

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isAdmin = in_array($roleName, ['admin', 'administrator', 'system administrator', 'system_administrator', 'super admin', 'super_admin']);
        $isSupervisor = $roleName === 'supervisor';

        return $query->where(function ($q) use ($officeName, $divisionName, $fullName, $user, $isAdmin, $isSupervisor) {
            // Clause A: Non-confidential documents in their office
            $q->where(function ($q1) use ($officeName, $divisionName, $isAdmin, $isSupervisor, $fullName, $user) {
                $q1->where('is_confidential', false)
                   ->where('office_assignment', $officeName)
                   ->where(function ($qSub) use ($isAdmin, $isSupervisor, $fullName, $user, $divisionName) {
                       if ($isAdmin || $isSupervisor) {
                           // Supervisors and admins see whatever is incoming to their office regardless of division/assigned user
                       } else {
                           // Regular users see if they are assigned, or if the division matches
                           $qSub->where('assigned_user', $fullName)
                                ->orWhere('assigned_user', $user->id)
                                ->orWhere(function ($qDiv) use ($divisionName) {
                                    if ($divisionName) {
                                        $qDiv->where('division_assignment', $divisionName);
                                    }
                                });
                       }
                   });
            })
            // Clause B: Confidential documents
            ->orWhere(function ($q2) use ($officeName, $fullName, $user, $isAdmin) {
                $q2->where('is_confidential', true)
                   ->where(function ($qSub) use ($officeName, $fullName, $user, $isAdmin) {
                       // The assigned user can see it
                       $qSub->where('assigned_user', $fullName)
                            ->orWhere('assigned_user', $user->id)
                            ->orWhere('assigned_user', $user->username);
                       
                       // The admin in that office can see it (if it's in their office)
                       if ($isAdmin) {
                           $qSub->orWhere('office_assignment', $officeName);
                       }
                   });
            })
            // Clause C: Specifically assigned to the user (even if not in their office, or external, etc.)
            ->orWhere(function ($q3) use ($fullName, $user) {
                $q3->where('assigned_user', $fullName)
                   ->orWhere('assigned_user', $user->id);
            })
            // Clause D: Internal send_to_all documents in creator's office
            ->orWhere(function ($qInt) use ($user) {
                $qInt->where('is_internal', true)
                     ->where('send_to_all', true)
                     ->whereHas('creator', function ($qC) use ($user) {
                         $qC->where('office_id', $user->office_id);
                     });
            });
        })->where(function ($qInternalCheck) {
            // Must not be internal unless it is send_to_all
            $qInternalCheck->where('is_internal', false)
                           ->orWhere(function ($qIntSub) {
                               $qIntSub->where('is_internal', true)
                                       ->where('send_to_all', true);
                           });
        })
          ->where('status', '!=', 'completed')
          ->where('status', '!=', 'archived')
          ->where('status', '!=', 'released');
    }

    public function scopeForOutgoing($query, User $user)
    {
        $officeName = $user->office?->name;

        return $query->where(function ($q) use ($user, $officeName) {
            $q->where(function ($q2) use ($user) {
                $q2->whereIn('status', ['released', 'outgoing', 'returned']);
                if ($user->office_id) {
                    $q2->whereHas('creator', function ($qC) use ($user) {
                        $qC->where('office_id', $user->office_id);
                    });
                } else {
                    $q2->where('created_by', $user->id);
                }
            });

            if ($officeName) {
                $q->orWhereHas('histories', function ($q3) use ($officeName) {
                    $q3->whereIn('action', ['assigned', 'transfer', 'returned', 'released'])
                       ->where('from_office', $officeName)
                       ->whereColumn('from_office', '!=', 'to_office');
                });
            }
        });
    }
}
