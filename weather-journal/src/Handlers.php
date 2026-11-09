<?php

declare(strict_types=1);

/**
 * All API endpoints. Each method takes the Request plus URL params
 * and returns a Response (or throws an HttpException).
 */
final class Handlers
{
    private readonly Closure $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly WeatherProvider $weather,
        ?Closure $clock = null,
        private readonly int $cacheTtlSeconds = 3600,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    // ---------------------------------------------------------------- cities

    public function listCities(Request $request, array $params): Response
    {
        $rows = $this->db->query(
            'SELECT c.id, c.name, c.country, c.latitude, c.longitude, c.timezone, c.created_at,
                    COUNT(n.id) AS note_count
               FROM cities c
          LEFT JOIN notes n ON n.city_id = c.id
           GROUP BY c.id
           ORDER BY c.name COLLATE NOCASE'
        )->fetchAll();

        return new Response(200, ['cities' => $rows]);
    }

    public function addCity(Request $request, array $params): Response
    {
        $name = trim((string) ($request->json()['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Validation failed', details: ['name' => 'Required, 1 to 100 characters']);
        }

        $place = $this->weather->geocode($name);
        if ($place === null) {
            throw new HttpException(422, "No city found matching \"$name\"");
        }

        $existing = $this->db->prepare('SELECT * FROM cities WHERE latitude = ? AND longitude = ?');
        $existing->execute([$place['latitude'], $place['longitude']]);
        if ($row = $existing->fetch()) {
            throw new HttpException(409, "{$row['name']} is already in your journal", details: ['city' => $row]);
        }

        $insert = $this->db->prepare(
            'INSERT INTO cities (name, country, latitude, longitude, timezone)
             VALUES (:name, :country, :latitude, :longitude, :timezone)'
        );
        $insert->execute($place);
        $city = $this->findCity((string) $this->db->lastInsertId());

        return new Response(201, $city, ['Location' => "/api/cities/{$city['id']}"]);
    }

    public function getCity(Request $request, array $params): Response
    {
        return new Response(200, $this->findCity($params['id']));
    }

    public function deleteCity(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        // Snapshots, notes and cached weather go too (ON DELETE CASCADE).
        $this->db->prepare('DELETE FROM cities WHERE id = ?')->execute([$city['id']]);

        return new Response(204);
    }

    // --------------------------------------------------------------- weather

    public function weather(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        $now = ($this->clock)();

        $stmt = $this->db->prepare('SELECT payload, fetched_at FROM weather_cache WHERE city_id = ?');
        $stmt->execute([$city['id']]);
        $cache = $stmt->fetch();

        $refresh = $request->queryString('refresh') === '1';
        if ($cache && !$refresh && $now - (int) $cache['fetched_at'] < $this->cacheTtlSeconds) {
            $data = json_decode($cache['payload'], true, 512, JSON_THROW_ON_ERROR);
            $fetchedAt = (int) $cache['fetched_at'];
            $cached = true;
        } else {
            $data = $this->weather->forecast($city['latitude'], $city['longitude'], $city['timezone']);
            $this->store((int) $city['id'], $data, $now);
            $fetchedAt = $now;
            $cached = false;
        }

        $today = substr((string) $data['current']['time'], 0, 10);
        $forecast = array_values(array_filter($data['daily'], fn (array $day) => $day['date'] >= $today));

        return new Response(200, [
            'city' => $city,
            'cached' => $cached,
            'fetched_at' => gmdate('c', $fetchedAt),
            'units' => ['temperature' => '°C', 'wind_speed' => 'km/h', 'precipitation' => 'mm', 'humidity' => '%'],
            'current' => $data['current'] + WeatherCodes::describe($data['current']['weather_code']),
            'forecast' => array_map(fn (array $day) => $day + WeatherCodes::describe($day['weather_code']), $forecast),
        ]);
    }

    /** Save the API response to the cache and record each day up to today in the history table. */
    private function store(int $cityId, array $data, int $now): void
    {
        $today = substr((string) $data['current']['time'], 0, 10);
        $fetchedAt = gmdate('c', $now);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO weather_cache (city_id, payload, fetched_at) VALUES (?, ?, ?)
                 ON CONFLICT (city_id) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at'
            )->execute([$cityId, json_encode($data, JSON_THROW_ON_ERROR), $now]);

            $upsert = $this->db->prepare(
                'INSERT INTO snapshots (city_id, date, temp_max, temp_min, precipitation, weather_code, fetched_at)
                 VALUES (:city_id, :date, :temp_max, :temp_min, :precipitation, :weather_code, :fetched_at)
                 ON CONFLICT (city_id, date) DO UPDATE SET
                     temp_max = excluded.temp_max,
                     temp_min = excluded.temp_min,
                     precipitation = excluded.precipitation,
                     weather_code = excluded.weather_code,
                     fetched_at = excluded.fetched_at'
            );
            foreach ($data['daily'] as $day) {
                if ($day['date'] > $today) {
                    continue; // forecasts aren't history yet
                }
                $upsert->execute([
                    'city_id' => $cityId,
                    'date' => $day['date'],
                    'temp_max' => $day['temp_max'],
                    'temp_min' => $day['temp_min'],
                    'precipitation' => $day['precipitation'],
                    'weather_code' => $day['weather_code'],
                    'fetched_at' => $fetchedAt,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function history(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        $to = $this->dateParam($request->queryString('to'), 'to') ?? gmdate('Y-m-d', ($this->clock)());
        $from = $this->dateParam($request->queryString('from'), 'from')
            ?? gmdate('Y-m-d', strtotime("$to -30 days"));
        if ($from > $to) {
            throw new HttpException(422, 'Validation failed', details: ['from' => 'Must be on or before "to"']);
        }

        // LEFT JOIN so days without a note still appear (note = null).
        $stmt = $this->db->prepare(
            'SELECT s.date, s.temp_max, s.temp_min, s.precipitation, s.weather_code, n.text AS note
               FROM snapshots s
          LEFT JOIN notes n ON n.city_id = s.city_id AND n.date = s.date
              WHERE s.city_id = :city_id AND s.date BETWEEN :from AND :to
           ORDER BY s.date DESC'
        );
        $stmt->execute(['city_id' => $city['id'], 'from' => $from, 'to' => $to]);
        $days = array_map(fn (array $d) => $d + WeatherCodes::describe($d['weather_code']), $stmt->fetchAll());

        return new Response(200, ['city' => $city, 'from' => $from, 'to' => $to, 'days' => $days]);
    }

    // ----------------------------------------------------------------- notes

    public function listNotes(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        $stmt = $this->db->prepare(
            'SELECT date, text, created_at, updated_at FROM notes WHERE city_id = ? ORDER BY date DESC'
        );
        $stmt->execute([$city['id']]);

        return new Response(200, ['city' => $city, 'notes' => $stmt->fetchAll()]);
    }

    /** PUT /api/cities/{id}/notes/{date}: creates the note (201) or replaces it (200). */
    public function putNote(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        $date = $this->dateParam($params['date'], 'date');
        $text = trim((string) ($request->json()['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 1000) {
            throw new HttpException(422, 'Validation failed', details: ['text' => 'Required, 1 to 1000 characters']);
        }

        $exists = $this->noteExists((int) $city['id'], $date);
        $this->db->prepare(
            "INSERT INTO notes (city_id, date, text) VALUES (?, ?, ?)
             ON CONFLICT (city_id, date) DO UPDATE SET text = excluded.text, updated_at = datetime('now')"
        )->execute([$city['id'], $date, $text]);

        $stmt = $this->db->prepare('SELECT date, text, created_at, updated_at FROM notes WHERE city_id = ? AND date = ?');
        $stmt->execute([$city['id'], $date]);

        return new Response($exists ? 200 : 201, $stmt->fetch());
    }

    public function deleteNote(Request $request, array $params): Response
    {
        $city = $this->findCity($params['id']);
        $date = $this->dateParam($params['date'], 'date');
        if (!$this->noteExists((int) $city['id'], $date)) {
            throw new HttpException(404, "No note for $date");
        }
        $this->db->prepare('DELETE FROM notes WHERE city_id = ? AND date = ?')->execute([$city['id'], $date]);

        return new Response(204);
    }

    // ----------------------------------------------------------------- stats

    public function stats(Request $request, array $params): Response
    {
        $days = filter_var($request->queryString('days', '30'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 365],
        ]);
        if ($days === false) {
            throw new HttpException(422, 'Validation failed', details: ['days' => 'Must be an integer from 1 to 365']);
        }
        $since = gmdate('Y-m-d', ($this->clock)() - $days * 86400);

        $stmt = $this->db->prepare(
            'SELECT c.id, c.name, c.country,
                    COUNT(s.id)                                      AS days_recorded,
                    ROUND(AVG(s.temp_max), 1)                        AS avg_high,
                    ROUND(AVG(s.temp_min), 1)                        AS avg_low,
                    MAX(s.temp_max)                                  AS record_high,
                    MIN(s.temp_min)                                  AS record_low,
                    ROUND(COALESCE(SUM(s.precipitation), 0), 1)      AS total_precipitation,
                    COALESCE(SUM(CASE WHEN s.precipitation >= 1 THEN 1 ELSE 0 END), 0) AS rainy_days
               FROM cities c
          LEFT JOIN snapshots s ON s.city_id = c.id AND s.date >= :since
           GROUP BY c.id
           ORDER BY avg_high DESC NULLS LAST, c.name'
        );
        $stmt->execute(['since' => $since]);
        $cities = $stmt->fetchAll();

        $withData = array_values(array_filter($cities, fn (array $c) => $c['days_recorded'] > 0));
        $pick = function (string $field) use ($withData): ?array {
            if (!$withData) {
                return null;
            }
            usort($withData, fn (array $a, array $b) => $b[$field] <=> $a[$field]);

            return ['id' => $withData[0]['id'], 'name' => $withData[0]['name'], $field => $withData[0][$field]];
        };

        return new Response(200, [
            'since' => $since,
            'days' => $days,
            'warmest_city' => $pick('avg_high'),
            'wettest_city' => $pick('total_precipitation'),
            'cities' => $cities,
        ]);
    }

    // --------------------------------------------------------------- helpers

    private function findCity(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new HttpException(404, 'City not found');
        }
        $stmt = $this->db->prepare('SELECT * FROM cities WHERE id = ?');
        $stmt->execute([(int) $id]);
        $city = $stmt->fetch();
        if (!$city) {
            throw new HttpException(404, 'City not found');
        }

        return $city;
    }

    private function noteExists(int $cityId, string $date): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM notes WHERE city_id = ? AND date = ?');
        $stmt->execute([$cityId, $date]);

        return (bool) $stmt->fetchColumn();
    }

    /** Validate a YYYY-MM-DD string (null passes through as "not given"). */
    private function dateParam(?string $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'Validation failed', details: [$field => 'Must be a real date in YYYY-MM-DD format']);
        }

        return $value;
    }
}
