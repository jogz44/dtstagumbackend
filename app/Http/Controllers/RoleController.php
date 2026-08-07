<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * Display a listing of roles with their aggregated user counts.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->get();

        return response()->json([
            'roles' => $roles
        ]);
    }
}
