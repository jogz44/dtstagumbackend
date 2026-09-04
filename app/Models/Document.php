<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tracking_number
 * @property string|null $created_by
 * @property string|null $origin_sender
 * @property string|null $sender_name
 * @property string|null $issued_by
 * @property string|null $contact_number
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
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Document extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tracking_number', 'created_by', 'origin_sender', 'sender_name', 'issued_by', 'contact_number',
        'document_type', 'priority_level', 'description', 'remarks', 'is_confidential', 'self_assign',
        'send_to_all', 'is_internal', 'office_assignment', 'division_assignment',
        'assigned_user', 'is_read_only', 'status', 'completed_at', 'due_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_confidential' => 'boolean',
            'self_assign' => 'boolean',
            'send_to_all' => 'boolean',
            'is_internal' => 'boolean',
            'is_read_only' => 'boolean',
            'completed_at' => 'datetime',
            'due_date' => 'date:Y-m-d',
        ];
    }

    /**
     * Automatically archive documents that have remained completed for 1 month and 1 week.
     */
    public static function autoArchiveCompleted(): int
    {
        $threshold = now()->subMonth()->subWeek();

        $expiredDocs = static::where('status', 'completed')
            ->where(function ($query) use ($threshold) {
                $query->where('completed_at', '<=', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('completed_at')
                            ->where('updated_at', '<=', $threshold);
                    });
            })
            ->get();

        $count = 0;
        foreach ($expiredDocs as $doc) {
            $statusBefore = $doc->status;
            $doc->update([
                'status' => 'archived',
            ]);

            DocumentHistory::record($doc, 'archived', null, [
                'remarks' => 'Automatically archived after being completed for 1 month and 1 week.',
                'status_before' => $statusBefore,
                'status_after' => 'archived',
            ]);
            $count++;
        }

        return $count;
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
        $officeNames = self::getSimilarOfficeNames($officeName);

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isAdmin = in_array($roleName, ['admin', 'administrator', 'system administrator', 'system_administrator', 'super admin', 'super_admin']);

        return $query->where('status', '!=', 'archived')
            ->where(function ($q) use ($user, $officeNames, $isAdmin) {
                // Documents currently assigned to the user's office or assigned to the user directly
                if (!empty($officeNames)) {
                    $q->whereIn('office_assignment', $officeNames);
                } else {
                    $q->where('assigned_user', (string) $user->id)
                      ->orWhere('assigned_user', $user->full_name)
                      ->orWhere('assigned_user', $user->username);
                }

                // Or created by the user, provided it is still in the user's office or unassigned
                $q->orWhere(function ($qCreated) use ($user, $officeNames) {
                    $qCreated->where('created_by', $user->id);
                    if (!empty($officeNames)) {
                        $qCreated->where(function ($qOff) use ($officeNames) {
                            $qOff->whereNull('office_assignment')
                                 ->orWhere('office_assignment', '')
                                 ->orWhereIn('office_assignment', $officeNames);
                        });
                    }
                });

                // If admin, also include documents created in the same office if still in the office or unassigned
                if ($isAdmin && $user->office_id) {
                    $q->orWhere(function ($qAdmin) use ($user, $officeNames) {
                        $qAdmin->whereHas('creator', function ($q2) use ($user) {
                            $q2->where('office_id', $user->office_id);
                        });
                        if (!empty($officeNames)) {
                            $qAdmin->where(function ($qOff) use ($officeNames) {
                                $qOff->whereNull('office_assignment')
                                     ->orWhere('office_assignment', '')
                                     ->orWhereIn('office_assignment', $officeNames);
                            });
                        }
                    });
                }
            })
            ->where(function ($qReceivedCheck) use ($user, $officeNames) {
                $qReceivedCheck->whereHas('creator', function ($qCreator) use ($user) {
                    if ($user->office_id) {
                        $qCreator->where('office_id', $user->office_id);
                    } else {
                        $qCreator->where('id', $user->id);
                    }
                })
                ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                    $qAck->whereNotNull('acknowledged_at')
                         ->where(function ($qUser) use ($user) {
                             $qUser->where('user_id', $user->id)
                                   ->orWhere('assigned_name', $user->full_name);
                         });
                })
                ->orWhereHas('histories', function ($qHist) use ($user, $officeNames) {
                    $qHist->whereIn('action', ['RECEIVED', 'ACKNOWLEDGED']);
                    if (!empty($officeNames)) {
                        $qHist->whereIn('to_office', $officeNames);
                    } else {
                        $qHist->where('performed_by', $user->id);
                    }
                });
            });
    }

    public function scopeForRegistry($query, User $user)
    {
        return $query->where('is_confidential', false)
            ->where('status', '!=', 'archived')
            ->where(function ($q) use ($user) {
                $officeName = $user->office?->name;
                $officeNames = self::getSimilarOfficeNames($officeName);

                if ($user->office_id) {
                    $q->whereHas('creator', function ($q2) use ($user) {
                        $q2->where('office_id', $user->office_id);
                    });
                }

                if (!empty($officeNames)) {
                    $q->orWhereIn('office_assignment', $officeNames)
                      ->orWhere('send_to_all', true)
                      ->orWhereHas('histories', function ($q3) use ($officeNames) {
                          $q3->where('action', 'SHARED')
                             ->whereIn('to_office', $officeNames);
                      });
                } else {
                    $q->orWhere('send_to_all', true);
                }
            });
    }

    public function scopeForIncoming($query, User $user)
    {
        $officeName = $user->office?->name;
        $officeNames = self::getSimilarOfficeNames($officeName);
        $divisionName = $user->division?->name;
        $fullName = $user->full_name;

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isAdmin = in_array($roleName, ['admin', 'administrator', 'system administrator', 'system_administrator', 'super admin', 'super_admin']);
        $isSupervisor = $roleName === 'supervisor';

        if (empty($officeNames)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('office_assignment', $officeNames)
            ->where(function ($q) use ($divisionName, $fullName, $user, $isAdmin, $isSupervisor) {
                // Non-confidential documents in their office
                $q->where(function ($q1) use ($divisionName, $isAdmin, $isSupervisor, $fullName, $user) {
                    $q1->where('is_confidential', false)
                       ->where(function ($qSub) use ($isAdmin, $isSupervisor, $fullName, $user, $divisionName) {
                           if ($isAdmin || $isSupervisor) {
                               // Supervisors and admins see whatever is incoming to their office
                           } else {
                               // Regular users see if they are assigned, or if the division matches, or unassigned in their office
                               $qSub->where('assigned_user', $fullName)
                                    ->orWhere('assigned_user', $user->id)
                                    ->orWhere('assigned_user', $user->username)
                                    ->orWhere(function ($qDiv) use ($divisionName) {
                                        if ($divisionName) {
                                            $qDiv->where('division_assignment', $divisionName);
                                        }
                                    })
                                    ->orWhereNull('assigned_user')
                                    ->orWhere('assigned_user', '');
                           }
                       });
                })
                // Confidential documents in their office
                ->orWhere(function ($q2) use ($fullName, $user, $isAdmin) {
                    $q2->where('is_confidential', true)
                       ->where(function ($qSub) use ($fullName, $user, $isAdmin) {
                           if ($isAdmin) {
                               // Admin in that office can see it
                           } else {
                               $qSub->where('assigned_user', $fullName)
                                    ->orWhere('assigned_user', $user->id)
                                    ->orWhere('assigned_user', $user->username);
                           }
                       });
                });
            })
            ->where(function ($qInternalCheck) use ($user) {
                // Must not be internal unless it is send_to_all, or it's a self-assigned doc
                $qInternalCheck->where('is_internal', false)
                               ->orWhere(function ($qIntSub) {
                                   $qIntSub->where('is_internal', true)
                                           ->where('send_to_all', true);
                               })
                               ->orWhere(function ($qSelfInternal) use ($user) {
                                   $qSelfInternal->where('self_assign', true)
                                                 ->where('created_by', $user->id);
                               });
            })
            ->where('status', '!=', 'completed')
            ->where('status', '!=', 'archived')
            ->where(function ($qReceivedCheck) use ($user, $officeNames) {
                $qReceivedCheck->whereHas('creator', function ($qCreator) use ($user) {
                    if ($user->office_id) {
                        $qCreator->where('office_id', $user->office_id);
                    } else {
                        $qCreator->where('id', $user->id);
                    }
                })
                ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                    $qAck->whereNotNull('acknowledged_at')
                         ->where(function ($qUser) use ($user) {
                             $qUser->where('user_id', $user->id)
                                   ->orWhere('assigned_name', $user->full_name);
                         });
                })
                ->orWhereHas('histories', function ($qHist) use ($user, $officeNames) {
                    $qHist->whereIn('action', ['RECEIVED', 'ACKNOWLEDGED']);
                    if (!empty($officeNames)) {
                        $qHist->whereIn('to_office', $officeNames);
                    } else {
                        $qHist->where('performed_by', $user->id);
                    }
                });
            });
    }

    public function scopeForOutgoing($query, User $user)
    {
        $officeName = $user->office?->name;
        $officeNames = self::getSimilarOfficeNames($officeName);

        return $query->where(function ($q) use ($user, $officeNames) {
            $q->where(function ($q2) use ($user) {
                $q2->whereIn('status', ['released', 'transferred', 'outgoing', 'returned']);
                if ($user->office_id) {
                    $q2->whereHas('creator', function ($qC) use ($user) {
                        $qC->where('office_id', $user->office_id);
                    });
                } else {
                    $q2->where('created_by', $user->id);
                }
            });

            if (!empty($officeNames)) {
                $q->orWhereHas('histories', function ($q3) use ($officeNames) {
                    $q3->whereIn('action', ['ASSIGNED', 'TRANSFERRED', 'FORWARDED', 'RELEASED', 'RETURNED'])
                       ->whereIn('from_office', $officeNames);
                });
            }

            // Also include if the user specifically performed the outgoing action
            $q->orWhereHas('histories', function ($q4) use ($user) {
                $q4->whereIn('action', ['TRANSFERRED', 'FORWARDED', 'RELEASED', 'RETURNED'])
                   ->where('performed_by', $user->id);
            });
        });
    }

    public static function getSimilarOfficeNames(?string $officeName): array
    {
        if (!$officeName) {
            return [];
        }
        
        $cleanName = str_ireplace(['office of the city', 'office of the', 'office', 'officer'], '', $officeName);
        $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
        
        if (strlen($cleanName) < 3) {
            return [$officeName];
        }

        $names = Office::query()
            ->where('name', 'LIKE', "%{$cleanName}%")
            ->pluck('name')
            ->toArray();
            
        return array_unique(array_merge([$officeName], $names));
    }
}
