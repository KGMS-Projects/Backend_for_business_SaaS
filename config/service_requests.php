<?php

return [

    /*
    | SLA: how many (calendar) hours the operations team has to complete a request,
    | counted from the moment a manager approves it. Drives `due_at` and `is_overdue`.
    */
    'sla_hours' => [
        'urgent' => 8,
        'high' => 24,
        'medium' => 72,
        'low' => 120,
    ],

    /*
    | Master switch for status-change notifications. Seeders turn it off so that
    | loading demo data does not queue dozens of e-mails.
    */
    'notifications_enabled' => (bool) env('NOTIFICATIONS_ENABLED', true),

    /*
    | API rate limits (requests per minute). Authenticated calls are limited per user,
    | anonymous ones per IP. Login attempts are limited per e-mail + IP to slow brute force.
    */
    'rate_limit' => [
        'api' => (int) env('RATE_LIMIT_API_PER_MINUTE', 60),
        'login' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),
    ],

    'pagination' => [
        'default' => 15,
        'max' => 100,
    ],
];
