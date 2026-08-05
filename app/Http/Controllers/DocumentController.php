<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $validated = $request->safe()->except('attachments');

        $document = DB::transaction(function () use ($request, $validated) {
            $document = Document::create([
                ...$validated,
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'created_by' => $request->user()->id,
                // Receive/uploaded documents are immediately visible to their creator as outgoing.
                'status' => 'outgoing',
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("documents/{$document->id}", 'local');

                $document->attachments()->create([
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            return $document;
        });

        return response()->json([
            'message' => 'Document saved successfully.',
            'document' => $document->load('attachments'),
        ], 201);
    }
}
