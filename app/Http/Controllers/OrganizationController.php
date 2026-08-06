<?php

namespace App\Http\Controllers;

use App\Models\Office;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $offices = Office::query()
            ->with(['divisions' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $offices]);
    }
}