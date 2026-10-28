<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: /ok', true, 302);

    return;
}

if ($path === '/timeout') {
    usleep(400000);
}

if ($path === '/failure') {
    http_response_code(503);
}

if ($path === '/cookie') {
    setcookie('transport', 'verified', ['path' => '/', 'httponly' => true]);
}

if ($path === '/stream') {
    header('Content-Type: text/plain');
    echo str_repeat('stream-data-', 1024);

    return;
}

header('Content-Type: application/json');
echo json_encode(['path' => $path, 'cookie' => $_COOKIE['transport'] ?? null], JSON_THROW_ON_ERROR);
