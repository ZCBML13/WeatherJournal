<?php

declare(strict_types=1);

// Run with:  php tests/run.php
// Uses an in-memory database and a fake weather provider, so no network is needed.

require_once __DIR__ . '/../src/App.php';

final class FakeWeather implements WeatherProvider
{
    public int $forecastCalls = 0;

    public function geocode(string $name): ?array
    {
        return match (strtolower($name)) {
            'montreal' => ['name' => 'Montreal', 'country' => 'Canada', 'latitude' => 45.5088, 'longitude' => -73.5878, 'timezone' => 'America/Toronto'],
            'lisbon' => ['name' => 'Lisbon', 'country' => 'Portugal', 'latitude' => 38.7167, 'longitude' => -9.1333, 'timezone' => 'Europe/Lisbon'],
            default => null,
        };
    }

    public function forecast(float $latitude, float $longitude, string $timezone): array
    {
        $this->forecastCalls++;
        $warm = $latitude < 40; // Lisbon is warmer and drier
        $daily = [];
        foreach (['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11'] as $i => $date) {
            $daily[] = [
                'date' => $date,
                'temp_max' => ($warm ? 20.0 : 5.0) + $i,
                'temp_min' => ($warm ? 12.0 : -2.0) + $i,
                'precipitation' => $warm ? 0.0 : 3.5,
                'weather_code' => $warm ? 0 : 63,
            ];
        }

        return [
            'current' => ['time' => '2026-11-09T14:00', 'temperature' => $warm ? 21.0 : 4.0, 'feels_like' => 2.0, 'humidity' => 70, 'wind_speed' => 12.0, 'weather_code' => $warm ? 0 : 63],
            'daily' => $daily,
        ];
    }
}

// --- tiny test harness -------------------------------------------------------

$passed = 0;
$failed = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "  ✗ $name\n      {$e->getMessage()}\n";
    }
}

/** Fresh app per test: returns [call(method, path, body?, query?), FakeWeather, &$now]. */
function app(): array
{
    $weather = new FakeWeather();
    $state = new ArrayObject(['now' => strtotime('2026-11-09 14:00:00 UTC')]);
    $router = App::router(Database::connect(':memory:'), $weather, fn () => $state['now']);
    $call = function (string $method, string $path, mixed $body = null, array $query = []) use ($router): Response {
        $raw = is_string($body) ? $body : ($body === null ? '' : json_encode($body));
        $response = $router->dispatch(new Request($method, $path, $query, $raw));
        // Round-trip through JSON so tests see exactly what a client would.
        if ($response->status !== 204) {
            json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $response;
    };

    return [$call, $weather, $state];
}

echo "Weather Journal tests\n";

test('adds a city by name and returns 201 with a Location header', function () {
    [$call] = app();
    $r = $call('POST', '/api/cities', ['name' => 'Montreal']);
    check($r->status === 201, "expected 201, got {$r->status}");
    check($r->data['name'] === 'Montreal' && $r->data['country'] === 'Canada', 'city fields');
    check($r->headers['Location'] === '/api/cities/1', 'Location header');
});

test('rejects duplicate, unknown and blank city names', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    check($call('POST', '/api/cities', ['name' => 'montreal'])->status === 409, 'duplicate → 409');
    check($call('POST', '/api/cities', ['name' => 'Atlantis'])->status === 422, 'unknown → 422');
    check($call('POST', '/api/cities', ['name' => '  '])->status === 422, 'blank → 422');
});

test('returns 400 for malformed or non-object JSON', function () {
    [$call] = app();
    $r = $call('POST', '/api/cities', '{"name": ');
    check($r->status === 400 && str_starts_with($r->data['error'], 'Invalid JSON'), 'malformed → 400');
    check($call('POST', '/api/cities', '["Montreal"]')->status === 400, 'array → 400');
    check($call('POST', '/api/cities', '')->status === 400, 'empty → 400');
});

test('lists cities alphabetically with note counts', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    $call('POST', '/api/cities', ['name' => 'Lisbon']);
    $call('PUT', '/api/cities/1/notes/2026-11-08', ['text' => 'Cold']);
    $cities = $call('GET', '/api/cities')->data['cities'];
    check(array_column($cities, 'name') === ['Lisbon', 'Montreal'], 'order');
    check($cities[1]['note_count'] === 1 && $cities[0]['note_count'] === 0, 'note counts');
});

test('404 for unknown routes and cities, 405 with Allow header for wrong method', function () {
    [$call] = app();
    check($call('GET', '/api/nope')->status === 404, 'unknown route');
    check($call('GET', '/api/cities/99')->status === 404, 'unknown city');
    check($call('GET', '/api/cities/abc')->status === 404, 'non-numeric id');
    $r = $call('PATCH', '/api/cities');
    check($r->status === 405 && $r->headers['Allow'] === 'GET, POST', '405 + Allow');
});

test('weather is fetched once, then served from cache until the TTL expires', function () {
    [$call, $weather, $state] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);

    $first = $call('GET', '/api/cities/1/weather');
    check($first->status === 200 && $first->data['cached'] === false, 'first call fetches');
    check($first->data['current']['description'] === 'Rain', 'weather code described');

    $second = $call('GET', '/api/cities/1/weather');
    check($second->data['cached'] === true && $weather->forecastCalls === 1, 'second call cached');

    $state['now'] += 3601;
    $third = $call('GET', '/api/cities/1/weather');
    check($third->data['cached'] === false && $weather->forecastCalls === 2, 'refetch after an hour');

    $call('GET', '/api/cities/1/weather', null, ['refresh' => '1']);
    check($weather->forecastCalls === 3, '?refresh=1 bypasses cache');
});

test('forecast only includes today onward; history stores only past days and today', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    $forecast = $call('GET', '/api/cities/1/weather')->data['forecast'];
    check(array_column($forecast, 'date') === ['2026-11-09', '2026-11-10', '2026-11-11'], 'forecast dates');

    $days = $call('GET', '/api/cities/1/history')->data['days'];
    check(array_column($days, 'date') === ['2026-11-09', '2026-11-08', '2026-11-07'], 'history dates (newest first)');
});

test('history joins notes onto days and validates date filters', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    $call('GET', '/api/cities/1/weather');
    $call('PUT', '/api/cities/1/notes/2026-11-08', ['text' => 'Rained all afternoon']);

    $days = $call('GET', '/api/cities/1/history', null, ['from' => '2026-11-08', 'to' => '2026-11-08'])->data['days'];
    check(count($days) === 1 && $days[0]['note'] === 'Rained all afternoon', 'note joined');

    check($call('GET', '/api/cities/1/history', null, ['from' => '2026-02-30'])->status === 422, 'impossible date');
    check($call('GET', '/api/cities/1/history', null, ['from' => '2026-11-09', 'to' => '2026-11-01'])->status === 422, 'from > to');
});

test('notes: PUT creates (201) then updates (200); DELETE removes (204) then 404', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    check($call('PUT', '/api/cities/1/notes/2026-11-09', ['text' => 'Sunny'])->status === 201, 'create');
    $r = $call('PUT', '/api/cities/1/notes/2026-11-09', ['text' => 'Actually cloudy']);
    check($r->status === 200 && $r->data['text'] === 'Actually cloudy', 'update');
    check(count($call('GET', '/api/cities/1/notes')->data['notes']) === 1, 'one note per day');
    check($call('PUT', '/api/cities/1/notes/not-a-date', ['text' => 'x'])->status === 422, 'bad date');
    check($call('PUT', '/api/cities/1/notes/2026-11-09', ['text' => ''])->status === 422, 'empty text');
    check($call('DELETE', '/api/cities/1/notes/2026-11-09')->status === 204, 'delete');
    check($call('DELETE', '/api/cities/1/notes/2026-11-09')->status === 404, 'delete again');
});

test('stats aggregate history per city and pick warmest / wettest', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    $call('POST', '/api/cities', ['name' => 'Lisbon']);
    $call('GET', '/api/cities/1/weather');
    $call('GET', '/api/cities/2/weather');

    $s = $call('GET', '/api/stats', null, ['days' => '7'])->data;
    check($s['warmest_city']['name'] === 'Lisbon', 'warmest');
    check($s['wettest_city']['name'] === 'Montreal', 'wettest');
    $mtl = $s['cities'][1];
    check($mtl['days_recorded'] === 3 && $mtl['avg_high'] === 6.0 && $mtl['rainy_days'] === 3, 'Montreal aggregates');
    check($mtl['total_precipitation'] === 10.5 && $mtl['record_low'] === -2.0, 'Montreal totals');
    check($call('GET', '/api/stats', null, ['days' => '0'])->status === 422, 'days validated');
});

test('deleting a city cascades to its notes, snapshots and cache', function () {
    [$call] = app();
    $call('POST', '/api/cities', ['name' => 'Montreal']);
    $call('GET', '/api/cities/1/weather');
    $call('PUT', '/api/cities/1/notes/2026-11-09', ['text' => 'Bye']);
    check($call('DELETE', '/api/cities/1')->status === 204, 'delete city');
    check($call('GET', '/api/cities/1')->status === 404, 'gone');
    $s = $call('GET', '/api/stats')->data;
    check($s['cities'] === [] && $s['warmest_city'] === null, 'nothing left');
});

echo "\n$passed passed, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
