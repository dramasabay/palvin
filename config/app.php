<?php
return [
    'app_name'             => getenv('PALVIN_APP_NAME')    ?: 'PALVIN Premium',
    'base_url'             => getenv('PALVIN_BASE_URL')    ?: 'https://app.palvinpavilion.com',
    'upload_dir'           => __DIR__ . '/../uploads',
    'default_logo'         => 'assets/img/default-logo.svg',
    'timezone'             => getenv('PALVIN_TIMEZONE')    ?: 'Asia/Phnom_Penh',
    'mysql_timezone_offset' => getenv('PALVIN_DB_TIMEZONE') ?: '+07:00',
];
