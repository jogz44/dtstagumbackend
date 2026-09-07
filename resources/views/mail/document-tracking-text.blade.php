Hi {{ $recipientName }},

Here are the tracking details and guest tracking link for your document:

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
📌 TRACK DOCUMENT AS GUEST:
{{ $guestTrackUrl }}
========================================

A QR code image (QR-{{ $trackingNumber }}.png) is also attached to this email. You can scan it anytime to track this document in real time.
