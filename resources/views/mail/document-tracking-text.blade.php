Hi {{ $recipientName }},

Here are the tracking details and tracking link for your document:

Subject / Particular: {{ $particular }}
Tracking Number: {{ $trackingNumber }}
Document Type: {{ $documentType }}
Priority Level: {{ $priorityLevel }}
Issuing Party / Origin: {{ $originSender }}
@if($dueDate && $dueDate !== 'No deadline')
Due Date: {{ $dueDate }}
@endif
Date Sent: {{ $dateSent }}

========================================
TRACK DOCUMENT:
Scan the QR code in the email or follow this link:
{{ $guestTrackUrl }}
========================================

--
Tagum Document Track and Trace System
