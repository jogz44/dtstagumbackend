<?php

namespace App\Http\Controllers;

use App\Services\DocumentAttentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttentionSummaryController extends Controller
{
    public function __invoke(Request $request, DocumentAttentionService $attentionService): JsonResponse
    {
        return response()->json($attentionService->getAttentionSummary($request->user()));
    }
}
