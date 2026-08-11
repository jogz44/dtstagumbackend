<?php

namespace App\Models;

use InvalidArgumentException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentHistory extends Model
{
    use HasUuids;

    public const REGISTERED = 'REGISTERED';
    public const RECEIVED = 'RECEIVED';
    public const TRANSFERRED = 'TRANSFERRED';
    public const FORWARDED = 'FORWARDED';
    public const RETURNED = 'RETURNED';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const ARCHIVED = 'ARCHIVED';
    public const RELEASED = 'RELEASED';
    public const ASSIGNED = 'ASSIGNED';
    public const REASSIGNED = 'REASSIGNED';

    public const EVENT_TYPES = [
        self::REGISTERED,
        self::RECEIVED,
        self::TRANSFERRED,
        self::FORWARDED,
        self::RETURNED,
        self::APPROVED,
        self::REJECTED,
        self::ARCHIVED,
        self::RELEASED,
        self::ASSIGNED,
        self::REASSIGNED,
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    // Disable standard timestamps since we only use custom created_at
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'tracking_number',
        'performed_by',
        'from_user_id',
        'to_user_id',
        'from_division_id',
        'to_division_id',
        'action',
        'status_before',
        'status_after',
        'from_office',
        'to_office',
        'remarks',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function fromDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'from_division_id');
    }

    public function toDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'to_division_id');
    }

    public function scopeInvolvingUser(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(function (Builder $involvementQuery) use ($userId) {
            $involvementQuery
                ->where('performed_by', $userId)
                ->orWhere('from_user_id', $userId)
                ->orWhere('to_user_id', $userId);
        });
    }

    public static function record(Document $document, string $eventType, User $performedBy, array $attributes = []): self
    {
        $eventType = strtoupper($eventType);

        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported document history event type [{$eventType}].");
        }

        return $document->histories()->create([
            ...$attributes,
            'tracking_number' => $attributes['tracking_number'] ?? $document->tracking_number,
            'performed_by' => $performedBy->id,
            'action' => $eventType,
        ]);
    }
}
