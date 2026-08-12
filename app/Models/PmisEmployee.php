<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PmisEmployee extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'pmis_no',
        'first_name',
        'middle_name',
        'last_name',
        'designation',
        'designation_code',
        'office',
        'employment_status',
        'telephone',
        'pmis_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'pmis_synced_at' => 'datetime',
        ];
    }
}
