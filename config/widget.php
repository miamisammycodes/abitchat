<?php

declare(strict_types=1);

return [
    'session_dual_accept' => env('WIDGET_SESSION_DUAL_ACCEPT', false),
    'session_ttl' => env('WIDGET_SESSION_TTL', 1800),

    'ip_init_per_min' => env('WIDGET_IP_INIT_PER_MIN', 10),
    'ip_message_per_min' => env('WIDGET_IP_MESSAGE_PER_MIN', 30),
    'ip_daily_cap' => env('WIDGET_IP_DAILY_CAP', 5000),
];
