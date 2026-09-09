<?php

namespace App\Http\Controllers;

use App\Mail\DocumentTrackingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DocumentEmailController extends Controller
{
    public function sendTrackingEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_email' => 'required|email',
            'recipient_name'  => 'nullable|string|max:255',
            'particular'      => 'required|string|max:500',
            'tracking_number' => 'required|string|max:100',
            'document_type'   => 'nullable|string|max:255',
            'priority_level'  => 'nullable|string|max:100',
            'origin_sender'   => 'nullable|string|max:255',
            'due_date'        => 'nullable|string|max:100',
            'date_sent'       => 'nullable|string|max:100',
            'guest_track_url' => 'required|string|max:1000',
            'qr_code_base64'  => 'nullable|string',
        ]);

        $qrImagePath = $this->resolveQrImage(
            $validated['guest_track_url'],
            $validated['tracking_number'],
            $request->input('qr_code_base64')
        );

        try {
            Mail::to($validated['recipient_email'])
                ->send(new DocumentTrackingMail(
                    recipientName:  $validated['recipient_name'] ?: $validated['recipient_email'],
                    particular:     $validated['particular'],
                    trackingNumber: $validated['tracking_number'],
                    documentType:   $validated['document_type'] ?? 'N/A',
                    priorityLevel:  $validated['priority_level'] ?? 'Normal',
                    originSender:   $validated['origin_sender'] ?? 'N/A',
                    dueDate:        $validated['due_date'] ?? 'No deadline',
                    dateSent:       $validated['date_sent'] ?? now()->format('M d, Y'),
                    guestTrackUrl:  $validated['guest_track_url'],
                    qrImagePath:    $qrImagePath,
                ));
        } catch (\Throwable $e) {
            Log::error('Failed to send document tracking email: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        } finally {
            if ($qrImagePath && file_exists($qrImagePath)) {
                @unlink($qrImagePath);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Tracking email and QR code successfully sent to ' . $validated['recipient_email'],
        ]);
    }

    /**
     * Resolve the QR code image path.
     * Prefers the base64 PNG sent from the client canvas (exact high-res render),
     * then attempts online QR image service, and falls back to GD.
     */
    private function resolveQrImage(string $url, string $trackingNumber, ?string $base64 = null): string
    {
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . Str::random(12) . '.png';

        // 1. Check if client canvas provided base64 data
        if (!empty($base64)) {
            $raw = $base64;
            if (str_contains($raw, ',')) {
                $raw = explode(',', $raw)[1];
            }
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && strlen($decoded) > 50) {
                file_put_contents($tmpPath, $decoded);
                return $tmpPath;
            }
        }

        // 2. Fallback: Online QR image generator
        try {
            $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=8&data=' . urlencode($url);
            $ctx = stream_context_create([
                'http' => ['timeout' => 2],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $content = @file_get_contents($qrApiUrl, false, $ctx);
            if ($content !== false && strlen($content) > 100) {
                file_put_contents($tmpPath, $content);
                return $tmpPath;
            }
        } catch (\Throwable $e) {
            // Proceed to GD fallback
        }

        // 3. Fallback: Draw placeholder box using GD if installed
        if (extension_loaded('gd')) {
            $img = imagecreatetruecolor(260, 260);
            $white = imagecolorallocate($img, 255, 255, 255);
            $darkGreen = imagecolorallocate($img, 22, 101, 52);
            $slate = imagecolorallocate($img, 71, 85, 105);

            imagefill($img, 0, 0, $white);
            // Border
            imagerectangle($img, 4, 4, 255, 255, $darkGreen);
            imagerectangle($img, 5, 5, 254, 254, $darkGreen);

            // Text
            imagestring($img, 4, 25, 100, 'TRACKING NUMBER:', $darkGreen);
            imagestring($img, 4, 25, 125, $trackingNumber, $slate);
            imagestring($img, 2, 25, 155, 'Scan or use link below', $slate);

            imagepng($img, $tmpPath);
            imagedestroy($img);
            return $tmpPath;
        }

        return '';
    }
}
