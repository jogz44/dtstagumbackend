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
}
