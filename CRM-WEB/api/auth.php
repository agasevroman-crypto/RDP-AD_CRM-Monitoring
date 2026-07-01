<?php
/**
 * API: Перевірка токена авторизації
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

$tokenInfo = validate_api_token();
echo json_encode([
    'success'     => true,
    'permissions' => $tokenInfo['permissions']
]);
