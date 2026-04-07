<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

 
$hiringSubject = $devMode
    ? 'hiring.testing.v1.>'
    : 'hiring.v1.>';

return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

 

    /**
     * Streams configuration (Consumer side)
     */
    'streams' => [
        [
            // AUTH STREAM
            'name' => $devMode
                ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS')
                : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),

            'durable' => $devMode
                ? env('NATS_AUTH_DURABLE', 'OPERATION_PIZZA_AUTH_TESTING_CONSUMER')
                : env('NATS_AUTH_DURABLE', 'OPERATION_PIZZA_AUTH_CONSUMER'),

            'filter_subject' => $authSubject,
        ],

    [
        // 🔥 NEW: HIRING STREAM
        'name' => $devMode
            ? env('NATS_HIRING_STREAM', 'HIRING_TESTING_EVENTS')
            : env('NATS_HIRING_STREAM', 'HIRING_EVENTS'),

        'durable' => $devMode
            ? env('NATS_HIRING_DURABLE', 'OPERATION_PIZZA_HIRING_TESTING_CONSUMER')
            : env('NATS_HIRING_DURABLE', 'OPERATION_PIZZA_HIRING_CONSUMER'),

        'filter_subject' => $hiringSubject,
    ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];