<?php

declare(strict_types=1);

// Front controller. Run with:  php -S localhost:8000 -t public public/index.php

require_once __DIR__ . '/../src/App.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Anything outside /api is the frontend.
if ($path !== '/api' && !str_starts_with($path, '/api/')) {
    if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
        return false; // let PHP's built-in server serve app.js / app.css directly
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    return;
}

ini_set('display_errors', '0'); // errors must never leak into the JSON output

$db = Database::connect(getenv('WEATHER_DB') ?: __DIR__ . '/../data/weather.db');
$router = App::router($db, new OpenMeteoClient());
$router->dispatch(Request::fromGlobals())->send();
