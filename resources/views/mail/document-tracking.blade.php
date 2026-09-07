<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Tracking Notification</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1e293b;
        }
        .wrapper {
            max-width: 620px;
            margin: 32px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .header {
            background: linear-gradient(135deg, #166534 0%, #15803d 100%);
            padding: 32px 40px 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            color: #ffffff;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .header p {
            margin: 6px 0 0;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
        }
        .body {
            padding: 36px 40px;
        }
        .greeting {
            font-size: 15px;
            color: #334155;
            margin-bottom: 20px;
        }
        .intro {
            font-size: 14px;
            color: #475569;
            margin-bottom: 24px;
        }
        .details-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 22px 24px;
            margin-bottom: 28px;
        }
        .details-card h3 {
            margin: 0 0 16px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
        }
        .detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        .detail-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .detail-label {
            color: #64748b;
            font-weight: 600;
            min-width: 160px;
            flex-shrink: 0;
        }
        .detail-value {
            color: #1e293b;
            font-weight: 500;
        }
        .priority-urgent { color: #dc2626; font-weight: 700; }
        .priority-high { color: #d97706; font-weight: 700; }
        .priority-normal { color: #166534; font-weight: 700; }
        .priority-low { color: #64748b; font-weight: 700; }

        .qr-section {
            text-align: center;
            margin-bottom: 28px;
            padding: 24px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .qr-section h3 {
            margin: 0 0 16px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .qr-section img {
            width: 160px;
            height: 160px;
            border-radius: 8px;
            border: 4px solid #ffffff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }
        .qr-caption {
            margin-top: 12px;
            font-size: 12px;
            color: #64748b;
        }

        .track-section {
            background: linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%);
            border: 1px solid #86efac;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 28px;
            text-align: center;
        }
        .track-section h3 {
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 700;
            color: #166534;
        }
        .track-btn {
            display: inline-block;
            margin-top: 12px;
            background: #166534;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .track-url {
            display: block;
            margin-top: 10px;
            font-size: 11px;
            color: #166534;
            word-break: break-all;
        }
        .footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 20px 40px;
            text-align: center;
        }
        .footer p {
            margin: 0;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Header -->
        <div class="header">
            <h1>📄 Document Tracking Notification</h1>
            <p>HR Document Tracking System</p>
        </div>

        <!-- Body -->
        <div class="body">
            <p class="greeting">
                @if($recipientName && str_contains($recipientName, '@'))
                    Hi <strong>{{ $recipientName }}</strong>,
                @elseif($recipientName)
                    Hi <strong>{{ $recipientName }}</strong>,
                @else
                    Hello,
                @endif
            </p>
            <p class="intro">
                Here are the tracking details and guest tracking link for your document:
            </p>

            <!-- Document Details -->
            <div class="details-card">
                <h3>Document Information</h3>
                <div class="detail-row">
                    <span class="detail-label">Subject / Particular</span>
                    <span class="detail-value">{{ $particular }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Tracking Number</span>
                    <span class="detail-value"><strong>{{ $trackingNumber }}</strong></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Document Type</span>
                    <span class="detail-value">{{ $documentType }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Priority Level</span>
                    <span class="detail-value">
                        @php $p = strtolower($priorityLevel); @endphp
                        <span class="@if($p === 'urgent') priority-urgent @elseif($p === 'high') priority-high @elseif($p === 'low') priority-low @else priority-normal @endif">
                            {{ $priorityLevel }}
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Issuing Party / Origin</span>
                    <span class="detail-value">{{ $originSender }}</span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Date Sent</span>
                    <span class="detail-value">{{ $dateSent }}</span>
                </div>
            </div>

            <!-- QR Code -->
            @if(!empty($qrImagePath) && file_exists($qrImagePath))
            <div class="qr-section">
                <h3>📷 Scan QR Code to Track</h3>
                <img src="{{ $message->embed($qrImagePath) }}"
                     alt="QR Code for {{ $trackingNumber }}" />
                <p class="qr-caption">Scan this QR code with your phone to track the document in real time.</p>
            </div>
            @endif

            <!-- Track Link -->
            <div class="track-section">
                <h3>📌 Track Document as Guest</h3>
                <p style="margin:0;font-size:13px;color:#166534;">
                    Click the button below or use the link to view real-time document progress without logging in.
                </p>
                <a href="{{ $guestTrackUrl }}" class="track-btn">Track Document →</a>
                <span class="track-url">{{ $guestTrackUrl }}</span>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is an automated notification from the <strong>HR Document Tracking System</strong>.</p>
            <p style="margin-top:4px;">Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
