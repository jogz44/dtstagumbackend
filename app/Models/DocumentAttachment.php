<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAttachment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'type',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
