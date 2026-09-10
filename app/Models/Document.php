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
        'tracking_number', 'created_by', 'origin_sender', 'sender_name', 'contact_person', 'issued_by', 'contact_number',
        'document_type', 'priority_level', 'description', 'remarks', 'is_confidential', 'self_assign',
        'send_to_all', 'is_internal', 'office_assignment', 'division_assignment',
        'assigned_user', 'assigned_user_id', 'is_read_only', 'status', 'received_at', 'due_at', 'completed_at', 'completed_by_user_id', 'due_date', 'notes',
    ];

    public function getContactPersonAttribute()
    {
        return $this->attributes['contact_person'] ?? $this->attributes['sender_name'] ?? null;
    }

    protected function casts(): array
    {
        return [
            'is_confidential' => 'boolean',
            'self_assign' => 'boolean',
            'send_to_all' => 'boolean',
            'is_internal' => 'boolean',
            'is_read_only' => 'boolean',
            'received_at' => 'datetime',
            'due_at' => 'datetime',
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

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DocumentAssignment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DocumentStatusHistory::class);
    }

    public function scopeForMyDocuments($query, User $user)
    {
        $officeName = $user->office?->name;
        $officeNames = self::getSimilarOfficeNames($officeName);

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isElevatedUser = in_array($roleName, ['admin', 'administrator', 'system administrator', 'system_administrator', 'super admin', 'super_admin', 'supervisor']);

        return $query->where('status', '!=', 'archived')
            ->where(function ($q) use ($user, $officeNames, $isElevatedUser) {
                if ($isElevatedUser) {
                    // Elevated users: Documents currently assigned to user's office or assigned to user directly
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

                    // Include documents created in the same office if still in the office or unassigned
                    if ($user->office_id) {
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
                } else {
                    // Regular receiver:
                    // 1. Directly assigned to user (user is current holder) AND has not been transmitted out by user
                    $q->where(function ($qHolder) use ($user) {
                        $qHolder->where(function ($qAssigned) use ($user) {
                            $qAssigned->where('assigned_user', (string) $user->id)
                                      ->orWhere('assigned_user', $user->full_name)
                                      ->orWhere('assigned_user', $user->username);
                        })->whereDoesntHave('histories', function ($qTx) use ($user) {
                            $qTx->whereIn('action', ['TRANSFERRED', 'FORWARDED', 'RELEASED', 'TRANSMITTED'])
                                ->where(function ($qUser) use ($user) {
                                    $qUser->where('performed_by', $user->id)
                                          ->orWhere('from_user_id', $user->id);
                                });
                        });
                    });

                    // 2. OR involved (created, acknowledged, or in history), PROVIDED the document is still in user's office or unassigned AND has not been transmitted out by user
                    $q->orWhere(function ($qInvolved) use ($user, $officeNames) {
                        $qInvolved->where(function ($qSub) use ($user) {
                            $qSub->where('created_by', $user->id)
                                 ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                                     $qAck->whereNotNull('acknowledged_at')
                                          ->where(function ($qUser) use ($user) {
                                              $qUser->where('user_id', $user->id)
                                                    ->orWhere('assigned_name', $user->full_name);
                                          });
                                 })
                                 ->orWhereHas('histories', function ($qHist) use ($user) {
                                     $qHist->where('performed_by', $user->id)
                                           ->orWhere('to_user_id', $user->id)
                                           ->orWhere('from_user_id', $user->id);
                                 });
                        })
                        ->whereDoesntHave('histories', function ($qTx) use ($user) {
                            $qTx->whereIn('action', ['TRANSFERRED', 'FORWARDED', 'RELEASED', 'TRANSMITTED'])
                                ->where(function ($qUser) use ($user) {
                                    $qUser->where('performed_by', $user->id)
                                          ->orWhere('from_user_id', $user->id);
                                });
                        })
                        ->whereNotIn('status', ['transferred', 'released', 'outgoing', 'in_transit']);

                        if (! empty($officeNames)) {
                            $qInvolved->where(function ($qOff) use ($officeNames) {
                                $qOff->whereNull('office_assignment')
                                     ->orWhere('office_assignment', '')
                                     ->orWhereIn('office_assignment', $officeNames);
                            });
                        }
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
        $isElevatedUser = $isAdmin || $isSupervisor;

        if ($isElevatedUser && empty($officeNames)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('status', '!=', 'completed')
            ->where('status', '!=', 'archived')
            ->where(function ($q) use ($divisionName, $fullName, $user, $isElevatedUser, $officeNames) {
                if ($isElevatedUser) {
                    if (!empty($officeNames)) {
                        $q->whereIn('office_assignment', $officeNames);
                    }
                } else {
                    // Receiver user: assigned to user or acknowledged or received in history
                    $q->where(function ($qSub) use ($fullName, $user) {
                        $qSub->where('assigned_user', $fullName)
                             ->orWhere('assigned_user', (string) $user->id)
                             ->orWhere('assigned_user', $user->username)
                             ->orWhereHas('acknowledgements', function ($qAck) use ($user, $fullName) {
                                 $qAck->whereNotNull('acknowledged_at')
                                      ->where(function ($qU) use ($user, $fullName) {
                                          $qU->where('user_id', $user->id)
                                             ->orWhere('assigned_name', $fullName);
                                      });
                             })
                             ->orWhereHas('histories', function ($qHist) use ($user) {
                                 $qHist->where('to_user_id', $user->id);
                             });
                    });
                }
            })
            ->where(function ($qInternalCheck) use ($user) {
                $qInternalCheck->where('is_internal', false)
                               ->orWhere(function ($qIntSub) {
                                   $qIntSub->where('is_internal', true)
                                           ->where('send_to_all', true);
                               })
                               ->orWhere(function ($qSelfInternal) use ($user) {
                                   $qSelfInternal->where('self_assign', true)
                                                 ->where('created_by', $user->id);
                               });
            });
    }

    public function scopeForOutgoing($query, User $user)
    {
        $officeName = $user->office?->name;
        $officeNames = self::getSimilarOfficeNames($officeName);

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isElevatedUser = in_array($roleName, ['admin', 'administrator', 'system administrator', 'system_administrator', 'super admin', 'super_admin', 'supervisor']);

        return $query->where(function ($q) use ($user, $officeNames, $isElevatedUser) {
            if ($isElevatedUser) {
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
            } else {
                // Receiver user: created by user or transferred/released by user
                $q->where('created_by', $user->id)
                  ->orWhereHas('histories', function ($q4) use ($user) {
                      $q4->whereIn('action', ['TRANSFERRED', 'FORWARDED', 'RELEASED', 'RETURNED'])
                         ->where(function ($qUser) use ($user) {
                             $qUser->where('performed_by', $user->id)
                                   ->orWhere('from_user_id', $user->id);
                         });
                  });
            }
        });
    }

    public function scopeForReceivedOrTransmitted($query, User $user)
    {
        $officeName = $user->office?->name;
        $officeNames = self::getSimilarOfficeNames($officeName);

        return $query->where(function ($q) use ($officeNames, $user) {
            if (! empty($officeNames)) {
                $q->whereIn('office_assignment', $officeNames)
                  ->orWhereIn('origin_sender', $officeNames)
                  ->orWhereHas('histories', function ($qH) use ($officeNames, $user) {
                      $qH->whereIn('from_office', $officeNames)
                         ->orWhereIn('to_office', $officeNames)
                         ->orWhere('performed_by', $user->id)
                         ->orWhere('from_user_id', $user->id)
                         ->orWhere('to_user_id', $user->id);
                  })
                  ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                      $qAck->where('user_id', $user->id);
                  });

                if ($user->office_id) {
                    $q->orWhereHas('creator', function ($qCreator) use ($user) {
                        $qCreator->where('office_id', $user->office_id);
                    });
                }
            } elseif ($user->office_id) {
                $q->whereHas('creator', function ($qCreator) use ($user) {
                    $qCreator->where('office_id', $user->office_id);
                });
            } else {
                $q->where('created_by', $user->id)
                  ->orWhere('assigned_user', $user->full_name)
                  ->orWhere('assigned_user', (string) $user->id);
            }
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
