<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentHistory extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    // Disable standard timestamps since we only use custom created_at
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'performed_by', 'action', 'from_office', 'to_office', 'remarks', 'metadata'
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
}
