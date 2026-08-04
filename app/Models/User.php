<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // <--- ADD THIS IMPORT

class User extends Authenticatable
{
    use HasApiTokens, Notifiable; // <--- ADD HasApiTokens HERE

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