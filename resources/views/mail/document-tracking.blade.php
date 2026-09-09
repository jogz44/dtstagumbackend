<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tagum Document Track and Trace Notification</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif;
            color: #1e293b;
        }
        .wrapper {
            max-width: 600px;
            margin: 32px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #166534 0%, #15803d 100%);
            padding: 30px 36px 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .header p {
            margin: 6px 0 0;
            color: rgba(255,255,255,0.88);
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0.2px;
        }
        .body {
            padding: 32px 36px;
        }
        .greeting {
            font-size: 15px;
            color: #334155;
            margin-bottom: 16px;
        }
        .intro {
            font-size: 14px;
            color: #475569;
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .details-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 24px;
        }
        .details-card h3 {
            margin: 0 0 14px;
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
            min-width: 150px;
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

        /* QR and Link Container */
        .qr-container {
            text-align: center;
            margin-top: 24px;
            padding: 26px 22px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .qr-heading {
            margin: 0 0 14px;
            font-size: 14px;
            font-weight: 700;
            color: #166534;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .qr-frame {
            display: inline-block;
            background: #ffffff;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .qr-frame img {
            width: 170px;
            height: 170px;
            display: block;
            margin: 0 auto;
        }
        .qr-sub {
            margin: 10px 0 0;
            font-size: 12px;
            color: #64748b;
        }
        .link-divider {
            margin: 20px 0 14px;
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            text-transform: lowercase;
        }
        .track-btn {
            display: inline-block;
            background: #166534;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .track-link {
            display: block;
            margin-top: 10px;
            font-size: 12px;
            color: #166534;
            word-break: break-all;
            text-decoration: underline;
        }
        .footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 20px 36px;
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
            <h1>Document Tracking Notification</h1>
            <p>Tagum Document Track and Trace System</p>
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
                Here are the tracking details and tracking link for your document:
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

            <!-- QR Code and Link Section -->
            <div class="qr-container">
                @if(!empty($qrImagePath) && file_exists($qrImagePath))
                <p class="qr-heading">Scan the QR code</p>
                <div class="qr-frame">
                    <img src="{{ $message->embed($qrImagePath) }}"
                         alt="QR Code for {{ $trackingNumber }}" />
                </div>
                <p class="qr-sub">Scan with your camera or QR scanner to view real-time document progress</p>

                <div class="link-divider">
                    <span>or follow this link</span>
                </div>
                @else
                <p class="qr-heading">Track Document</p>
                <p class="qr-sub" style="margin-bottom: 14px;">Follow the link below to view real-time document progress:</p>
                @endif

                <div class="action-wrap">
                    <a href="{{ $guestTrackUrl }}" class="track-btn">Track Document →</a>
                    <a href="{{ $guestTrackUrl }}" class="track-link">{{ $guestTrackUrl }}</a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is an automated notification from the <strong>Tagum Document Track and Trace System</strong>.</p>
            <p style="margin-top:4px;">Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
