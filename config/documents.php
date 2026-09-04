<?php

return [
    'due_reminder_days' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('DOCUMENT_DUE_REMINDER_DAYS', '3,1'))
    ), fn (int $day): bool => $day > 0)),
];
