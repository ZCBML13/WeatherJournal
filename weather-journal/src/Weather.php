<?php

declare(strict_types=1);

/**
 * Anything that can look up cities and forecasts. The real app uses
 * Open-Meteo; the tests swap in a fake so they never touch the network.
 */
interface WeatherProvider
{
    /** @return array{name: string, country: ?string, latitude: float, longitude: float, timezone: string}|null */
    public function geocode(string $name): ?array;

    /**
     * Returns the response flattened into our own format:
     *   ['current' => [...], 'daily' => [['date' => 'YYYY-MM-DD', ...], ...]]
     */
    public function forecast(float $latitude, float $longitude, string $timezone): array;
}

final class OpenMeteoClient implements WeatherProvider
{
    private const GEOCODE_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function geocode(string $name): ?array
    {
        $data = $this->get(self::GEOCODE_URL, [
            'name' => $name,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);

        $hit = $data['results'][0] ?? null;
        if (!is_array($hit) || !isset($hit['latitude'], $hit['longitude'], $hit['name'])) {
            return null;
        }

        return [
            'name' => (string) $hit['name'],
            'country' => isset($hit['country']) ? (string) $hit['country'] : null,
            'latitude' => (float) $hit['latitude'],
            'longitude' => (float) $hit['longitude'],
            'timezone' => (string) ($hit['timezone'] ?? 'auto'),
        ];
    }

    public function forecast(float $latitude, float $longitude, string $timezone): array
    {
        $data = $this->get(self::FORECAST_URL, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => $timezone,
            'past_days' => 7,      // back-fills the history table
            'forecast_days' => 7,
        ]);

        if (!isset($data['current']['time'], $data['daily']['time']) || !is_array($data['daily']['time'])) {
            throw new HttpException(502, 'Unexpected response from the weather service');
        }

        // Open-Meteo returns "columns" (one array per field); turn them into one object per day.
        $c = $data['current'];
        $d = $data['daily'];
        $daily = [];
        foreach ($d['time'] as $i => $date) {
            $daily[] = [
                'date' => $date,
                'temp_max' => $d['temperature_2m_max'][$i] ?? null,
                'temp_min' => $d['temperature_2m_min'][$i] ?? null,
                'precipitation' => $d['precipitation_sum'][$i] ?? null,
                'weather_code' => $d['weather_code'][$i] ?? null,
            ];
        }

        return [
            'current' => [
                'time' => $c['time'],
                'temperature' => $c['temperature_2m'] ?? null,
                'feels_like' => $c['apparent_temperature'] ?? null,
                'humidity' => $c['relative_humidity_2m'] ?? null,
                'wind_speed' => $c['wind_speed_10m'] ?? null,
                'weather_code' => $c['weather_code'] ?? null,
            ],
            'daily' => $daily,
        ];
    }

    private function get(string $url, array $params): array
    {
        $ch = curl_init($url . '?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => 'weather-journal/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            throw new HttpException(502, 'Weather service unreachable: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            throw new HttpException(502, "Weather service returned HTTP $status");
        }

        try {
            $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(502, 'Weather service returned invalid JSON');
        }

        return is_array($data) ? $data : [];
    }
}

/** WMO weather interpretation codes, as used by Open-Meteo. */
final class WeatherCodes
{
    private const CODES = [
        0 => ['Clear sky', '☀️'],
        1 => ['Mainly clear', '🌤️'],
        2 => ['Partly cloudy', '⛅'],
        3 => ['Overcast', '☁️'],
        45 => ['Fog', '🌫️'],
        48 => ['Freezing fog', '🌫️'],
        51 => ['Light drizzle', '🌦️'],
        53 => ['Drizzle', '🌦️'],
        55 => ['Heavy drizzle', '🌧️'],
        56 => ['Freezing drizzle', '🌧️'],
        57 => ['Freezing drizzle', '🌧️'],
        61 => ['Light rain', '🌦️'],
        63 => ['Rain', '🌧️'],
        65 => ['Heavy rain', '🌧️'],
        66 => ['Freezing rain', '🌧️'],
        67 => ['Freezing rain', '🌧️'],
        71 => ['Light snow', '🌨️'],
        73 => ['Snow', '🌨️'],
        75 => ['Heavy snow', '❄️'],
        77 => ['Snow grains', '🌨️'],
        80 => ['Rain showers', '🌦️'],
        81 => ['Rain showers', '🌧️'],
        82 => ['Violent rain showers', '⛈️'],
        85 => ['Snow showers', '🌨️'],
        86 => ['Heavy snow showers', '❄️'],
        95 => ['Thunderstorm', '⛈️'],
        96 => ['Thunderstorm with hail', '⛈️'],
        99 => ['Thunderstorm with hail', '⛈️'],
    ];

    /** @return array{description: string, icon: string} */
    public static function describe(?int $code): array
    {
        [$description, $icon] = self::CODES[$code] ?? ['Unknown', '❔'];

        return ['description' => $description, 'icon' => $icon];
    }
}
