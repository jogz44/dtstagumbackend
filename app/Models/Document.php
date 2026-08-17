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
        'assigned_user', 'is_read_only', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_confidential' => 'boolean',
            'self_assign' => 'boolean',
            'send_to_all' => 'boolean',
            'is_internal' => 'boolean',
            'is_read_only' => 'boolean',
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
        $divisionName = $user->division?->name;
        $fullName = $user->full_name;

        return $query->where('status', '!=', 'archived')
            ->where(function ($q) use ($user, $officeName, $divisionName, $fullName) {
                // Outgoing/Created by user (non-confidential)
                $q->where(function ($q1) use ($user) {
                    $q1->where('created_by', $user->id)
                       ->where('is_confidential', false)
                       ->where(function ($q2) {
                           $q2->where('self_assign', true)
                              ->orWhere('is_internal', false);
                       });
                })
                // OR incoming/assigned (non-confidential)
                ->orWhere(function ($qIncoming) use ($officeName, $divisionName, $fullName, $user) {
                    $qIncoming->where('is_internal', false)
                       ->where('is_confidential', false)
                       ->where(function ($q3) use ($officeName, $divisionName, $fullName, $user) {
                           $q3->where('assigned_user', $fullName)
                             ->orWhere('assigned_user', $user->id)
                             ->orWhere(function ($q4) use ($officeName, $divisionName) {
                                 $q4->where('office_assignment', $officeName)
                                    ->when($divisionName, function ($q5) use ($divisionName) {
                                        $q5->where('division_assignment', $divisionName);
                                    });
                              });
                       });
                })
                // OR confidential documents where the user is the assigned user
                ->orWhere(function ($qConfidential) use ($user, $fullName) {
                    $qConfidential->where('is_confidential', true)
                        ->where(function ($qInner) use ($user, $fullName) {
                            $qInner->where('assigned_user', $fullName)
                                   ->orWhere('assigned_user', $user->id)
                                   ->orWhere('assigned_user', $user->username);
                        });
                });
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

        return $query->where(function ($q) use ($officeName, $divisionName, $fullName, $user) {
            // Specifically assigned to this user
            $q->where('assigned_user', $fullName)
              ->orWhere('assigned_user', $user->id)
              ->orWhere(function ($q2) use ($officeName, $divisionName) {
                  $q2->where('is_confidential', false)
                     ->where('office_assignment', $officeName)
                     ->when($divisionName, function ($q3) use ($divisionName) {
                         $q3->where('division_assignment', $divisionName);
                     });
              });
        })->where('is_internal', false)
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
