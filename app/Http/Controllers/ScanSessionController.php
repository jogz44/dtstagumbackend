<?php

namespace App\Http\Controllers;

use App\Models\ScanPage;
use App\Models\ScanSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanSessionController extends Controller
{
    /**
     * Create a new scan session.
     * POST /api/scan-session
     */
    public function store(Request $request): JsonResponse
    {
        $token = (string) Str::uuid();

        $session = ScanSession::create([
            'token' => $token,
            'status' => 'pending',
        ]);

        return response()->json([
            'token' => $session->token,
            'status' => $session->status,
        ], 201);
    }

    /**
     * Poll the session status and retrieve new pages since a given page ID.
     * GET /api/scan-session/{token}?last_page_id=0
     *
     * This is the core polling endpoint the desktop uses to get real-time updates.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $session = ScanSession::where('token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $lastPageId = (int) $request->query('last_page_id', 0);

        // Only return pages newer than the last known page (incremental updates)
        $newPages = ScanPage::where('session_token', $token)
            ->where('id', '>', $lastPageId)
            ->orderBy('id')
            ->get()
            ->map(fn (ScanPage $page) => [
                'id' => $page->id,
                'page_number' => $page->page_number,
                'file_path' => $page->file_path,
                'url' => route('scan-pages.view', ['token' => $token, 'pageId' => $page->id]),
                'created_at' => $page->created_at?->toIso8601String(),
            ]);

        $totalPages = ScanPage::where('session_token', $token)->count();

        return response()->json([
            'status' => $session->status,
            'new_pages' => $newPages,
            'total_pages' => $totalPages,
            'last_page_id' => $newPages->isNotEmpty() ? $newPages->last()['id'] : $lastPageId,
        ]);
    }

    /**
     * Update session status (connected, completed).
     * PUT /api/scan-session/{token}/status
     */
    public function updateStatus(Request $request, string $token): JsonResponse
    {
        $session = ScanSession::where('token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:connected,completed',
        ]);

        $session->update(['status' => $validated['status']]);

        return response()->json([
            'status' => $session->status,
        ]);
    }

    /**
     * Upload a scanned page image.
     * POST /api/scan-session/{token}/pages
     *
     * Accepts multipart form data with:
     *  - image: the scanned page file (JPEG/PNG)
     *  - page_number: integer
     */
    public function storePage(Request $request, string $token): JsonResponse
    {
        $session = ScanSession::where('token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png|max:10240', // 10MB max
            'page_number' => 'required|integer|min:1',
        ]);

        $file = $request->file('image');
        $fileName = 'page_' . $request->page_number . '_' . time() . '.' . $file->getClientOriginalExtension();
        $disk = config('filesystems.default', 'local');

        $path = $file->storeAs(
            "scan-sessions/{$token}",
            $fileName,
            $disk
        );

        $page = ScanPage::create([
            'session_token' => $token,
            'file_path' => $path,
            'page_number' => $request->page_number,
            'created_at' => now(),
        ]);

        return response()->json([
            'id' => $page->id,
            'page_number' => $page->page_number,
            'file_path' => $page->file_path,
            'url' => route('scan-pages.view', ['token' => $token, 'pageId' => $page->id]),
        ], 201);
    }

    /**
     * Serve a scanned page image file.
     * GET /api/scan-session/{token}/pages/{pageId}/view
     */
    public function viewPage(string $token, int $pageId)
    {
        $page = ScanPage::where('session_token', $token)->where('id', $pageId)->first();

        if (! $page) {
            abort(404, 'Page not found');
        }

        $disk = config('filesystems.default', 'local');

        if (! Storage::disk($disk)->exists($page->file_path)) {
            abort(404, 'File not found');
        }

        return Storage::disk($disk)->response($page->file_path);
    }

    /**
     * Download all pages for a session as a zip or return file paths.
     * GET /api/scan-session/{token}/download
     */
    public function download(string $token)
    {
        $session = ScanSession::where('token', $token)->first();

        if (! $session) {
            abort(404, 'Session not found');
        }

        $pages = ScanPage::where('session_token', $token)
            ->orderBy('page_number')
            ->get();

        $files = $pages->map(fn (ScanPage $page) => [
            'url' => route('scan-pages.view', ['token' => $token, 'pageId' => $page->id]),
            'page_number' => $page->page_number,
            'file_path' => $page->file_path,
        ]);

        return response()->json([
            'pages' => $files,
        ]);
    }
}
