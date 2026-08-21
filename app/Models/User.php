<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // <--- ADD THIS IMPORT

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    // The users table uses UUID as the primary key
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'username',
        'password',
        'fullname',
        'employee_id',
        'birthdate',
        'office',
        'division',
        'position',
        'role',
        'status',
        'email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}