<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PmisEmployee extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'pmis_no',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
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
            'birth_date' => 'date',
            'pmis_synced_at' => 'datetime',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'pmis_employee_id');
    }
}
