<?php

return [
    'dsn' => getenv('CRAFT_DB_DSN') ?: getenv('DB_DSN'),
    'user' => getenv('CRAFT_DB_USER') ?: getenv('DB_USER'),
    'password' => getenv('CRAFT_DB_PASSWORD') ?: getenv('DB_PASSWORD'),
    'schema' => getenv('CRAFT_DB_SCHEMA') ?: getenv('DB_SCHEMA'),
    'tablePrefix' => getenv('CRAFT_DB_TABLE_PREFIX') ?: getenv('DB_TABLE_PREFIX'),
];
