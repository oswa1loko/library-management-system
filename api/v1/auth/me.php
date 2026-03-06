<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
$user = api_require_login();

api_json([
    'ok' => true,
    'user' => $user,
]);

