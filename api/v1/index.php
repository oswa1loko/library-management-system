<?php
require_once __DIR__ . '/_bootstrap.php';

api_json([
    'ok' => true,
    'name' => 'LibraryManage API',
    'version' => 'v1',
    'endpoints' => [
        'GET /librarymanage/api/v1/auth/me.php',
        'GET /librarymanage/api/v1/auth/tokens.php (session login required)',
        'POST /librarymanage/api/v1/auth/token_create.php (session login required)',
        'POST /librarymanage/api/v1/auth/token_revoke.php (session login required)',
        'POST /librarymanage/api/v1/auth/token_rotate.php (session login required)',
        'POST /librarymanage/api/v1/auth/token_revoke_all.php (session login required)',
        'GET /librarymanage/api/v1/books/index.php',
        'GET /librarymanage/api/v1/borrows/my.php',
        'POST /librarymanage/api/v1/borrows/create.php (Bearer token + write scope required)',
        'POST /librarymanage/api/v1/borrows/return_request.php (Bearer token + write scope required)',
        'GET /librarymanage/api/v1/penalties/my.php',
        'GET /librarymanage/api/v1/payments/my.php',
        'POST /librarymanage/api/v1/payments/create.php (Bearer token + write scope required)',
        'GET /librarymanage/api/v1/users/index.php (admin only)',
    ],
]);
