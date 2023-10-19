<?php

return [
    'outgoing_listener_mode' => env('INTERBUS_OUTGOING_LISTENER_MODE', \LaravelInterbus\InterbusMode::ASYNC),

    'connections' => [
        'default' => env('INTERBUS_TRANSPORT', 'rdkafka'),

        'rdkafka' => [
            'factory' => '\Enqueue\RdKafka\RdKafkaConnectionFactory',
            'config' => [
                'global' => [
                    'log_level' => '3',
                    'metadata.broker.list' => env('KAFKA_HOST'),
                    'sasl.mechanisms' => env('KAFKA_MECHANISM'),
                    'sasl.username' => env('KAFKA_USERNAME'),
                    'sasl.password' => env('KAFKA_PASSWORD'),
                    'security.protocol' => env('KAFKA_PROTOCOL'),
                    'client.id' => env('KAFKA_CONSUMER_GROUP'),
                    'group.id' => env('KAFKA_CONSUMER_GROUP'),
                ],
                'topic' => [
                    'auto.offset.reset' => env('KAFKA_INITIAL_OFFSET', 'earliest'),
                ],
            ],
        ],

        'testing' => [
            'factory' => Enqueue\Dbal\DbalConnectionFactory::class,
            'config' => env('INTERBUS_TESTING_CONFIG', 'sqlite:///:memory:'),
        ]
    ],

    'namespaces' => [
        'generic_events' => env('INTERBUS_GENERIC_EVENT_NAMESPACE', 'App\Events\Interbus'),
        'intentions' => env('INTERBUS_INTENTIONS_NAMESPACE', 'App\Jobs\Interbus'),
    ],

    'compress' => [
        'enabled' => env('INTERBUS_COMPRESS_ENABLED', false),
        'level' => env('INTERBUS_COMPRESS_LEVEL', 9),
    ],

    'offload' => [
        'disk' => env('INTERBUS_OFFLOAD_DISK'),
        'custom_bucket' => env('INTERBUS_OFFLOAD_CUSTOM_BUCKET', 'heavy-payloads'),
        'size' => env('INTERBUS_OFFLOAD_SIZE', 1024),
    ],

    'default_outgoing_pb_topics' => env('INTERBUS_OUTGOING_PB_TOPIC', 'default'),
    'outgoing_pb_topics' => [
    ],

    'protos' => [
    ],
];
