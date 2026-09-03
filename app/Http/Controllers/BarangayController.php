<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use Illuminate\Http\JsonResponse;

class BarangayController extends Controller
{
    public function index(): JsonResponse
    {
        $barangays = Barangay::active()
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'city']);

        return response()->json([
            'data' => $barangays,
        ]);
    }
}
