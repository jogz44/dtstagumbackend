<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $token
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ScanSession extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'token';

    protected $fillable = [
        'token',
        'status',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(ScanPage::class, 'session_token', 'token');
    }
}
