<?php
return [
    'default' => env('DB_CONNECTION', 'mongodb'),
    'connections' => [
        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('DB_URI', 'mongodb://mongo:27017'),
            'database' => env('DB_DATABASE', 'fluxos_luiz'),
            'rename_embedded_id_field' => false,
            'options' => ['appName' => env('APP_NAME', 'Fluxos Luiz')],
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
