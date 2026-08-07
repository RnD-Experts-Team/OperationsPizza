<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

$hiringSubject = $devMode
    ? 'hiring.testing.v1.>'
    : 'hiring.v1.>';

$operationsSubject = $devMode
    ? 'operations.testing.v1.>'
    : 'operations.v1.>';

$notificationsSubject = $devMode
    ? 'notifications.testing.v1.>'
    : 'notifications.v1.>';

return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    /*
     |--------------------------------------------------------------------------
     | Publishers
     |--------------------------------------------------------------------------
     | Streams THIS service publishes to. Subject → stream is resolved by
     | JetStreamPublisher::resolvePublishTarget().
     */
    'publishers' => [
        [
            'name' => $devMode
                ? env('NATS_OPERATIONS_STREAM', 'OPERATIONS_TESTING_EVENTS')
                : env('NATS_OPERATIONS_STREAM', 'OPERATIONS_EVENTS'),
            'subjects' => [$operationsSubject],
        ],
        [
            'name' => $devMode
                ? env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_TESTING_EVENTS')
                : env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_EVENTS'),
            'subjects' => [$notificationsSubject],
        ],
    ],

    /**
     * Streams consumed by THIS service. Each stream gets its own durable pull
     * consumer.
     *
     * NOTE: streams and durable consumers are created MANUALLY via the NATS
     * CLI — the app never calls create() (see JetStreamConsumer::getOrInitConsumer).
     * Before this consumer can do anything, someone must run:
     *
     *   nats stream add OPERATIONS_EVENTS --subjects 'operations.v1.>'
     *   nats consumer add AUTH_EVENTS   OPERATIONS_AUTH_CONSUMER   --filter 'auth.v1.>'   --pull
     *   nats consumer add HIRING_EVENTS OPERATIONS_HIRING_CONSUMER --filter 'hiring.v1.>' --pull
     *
     * ...plus the _TESTING_ variants when DEV_MODE=1.
     */
    'streams' => [
        [
            'name' => $devMode
                ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS')
                : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => $devMode
                ? env('NATS_AUTH_DURABLE', 'OPERATIONS_AUTH_TESTING_CONSUMER')
                : env('NATS_AUTH_DURABLE', 'OPERATIONS_AUTH_CONSUMER'),
            'filter_subject' => $authSubject,
        ],
        [
            'name' => $devMode
                ? env('NATS_HIRING_STREAM', 'HIRING_TESTING_EVENTS')
                : env('NATS_HIRING_STREAM', 'HIRING_EVENTS'),
            'durable' => $devMode
                ? env('NATS_HIRING_DURABLE', 'OPERATIONS_HIRING_TESTING_CONSUMER')
                : env('NATS_HIRING_DURABLE', 'OPERATIONS_HIRING_CONSUMER'),
            'filter_subject' => $hiringSubject,
        ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
