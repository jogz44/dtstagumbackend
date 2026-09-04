<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $session_token
 * @property string $file_path
 * @property int $page_number
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ScanPage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_token',
        'file_path',
        'page_number',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'session_token', 'token');
    }
}
