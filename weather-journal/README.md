# Weather Journal

A small PHP app that tracks the weather in cities you care about and lets you keep a one-line journal for each day.

It pulls live data from the free [Open-Meteo](https://open-meteo.com/) API (no key needed), stores it in **SQLite**, and serves everything through its own **JSON REST API**. A single vanilla-JS page uses that API.

It's written in plain PHP 8.1+ with no framework and no Composer, so every part is visible: routing, validation, PDO, cURL and JSON handling.

## What it shows

| Skill | Where to look |
|---|---|
| **PHP** | `src/Router.php` (a regex router with 404/405 handling), `src/Http.php` (request/response, JSON errors), `src/Handlers.php` (validation, status codes) |
| **SQL** | `schema.sql` (foreign keys with `ON DELETE CASCADE`, `UNIQUE` and `CHECK` constraints), plus prepared statements everywhere, `INSERT … ON CONFLICT DO UPDATE` upserts, a transaction, `LEFT JOIN` (history + notes) and `GROUP BY` aggregates (`/api/stats`) |
| **JSON** | Parses Open-Meteo's column-shaped JSON and flattens it to one object per day. Strict request-body parsing (400 on bad JSON). Caches raw API responses as JSON in SQLite. Every response, errors included, is JSON |
| **APIs** | **Consumes** Open-Meteo geocoding + forecast via cURL (`src/Weather.php`), with a 1-hour cache so it doesn't call it on every request. **Provides** a REST API with correct status codes (201, 204, 400, 404, 405, 409, 422, 502) |

## Run it

Requires PHP 8.1+ with the `pdo_sqlite` and `curl` extensions (both are on by default in most installs).

```bash
cd weather-journal
php -S localhost:8000 -t public public/index.php
```

Open http://localhost:8000. The database is created at `data/weather.db` on first request. Set `WEATHER_DB=/path/to/file.db` to put it somewhere else.

## Tests

```bash
php tests/run.php
```

The tests use an in-memory database and a fake weather provider, so they run offline.

## API

All bodies are JSON. Errors look like `{"error": "...", "details": {...}}`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/cities` | List saved cities (with note counts) |
| `POST` | `/api/cities` | Add a city: `{"name": "Montreal"}`. Geocoded via Open-Meteo. **201**, or 409 if already saved, 422 if not found |
| `GET` | `/api/cities/{id}` | One city |
| `DELETE` | `/api/cities/{id}` | Remove a city and all its data. **204** |
| `GET` | `/api/cities/{id}/weather` | Current conditions + forecast. Cached for 1 hour; `?refresh=1` forces a refetch |
| `GET` | `/api/cities/{id}/history` | Daily history with your notes. `?from=YYYY-MM-DD&to=YYYY-MM-DD` (default: last 30 days) |
| `GET` | `/api/cities/{id}/notes` | All your notes for a city |
| `PUT` | `/api/cities/{id}/notes/{date}` | Create (**201**) or replace (200) the note for a day: `{"text": "..."}` |
| `DELETE` | `/api/cities/{id}/notes/{date}` | Delete a note. **204** |
| `GET` | `/api/stats` | Per-city averages, records, rainfall, plus warmest/wettest city. `?days=30` (1–365) |

### Try it with curl

```bash
curl -X POST localhost:8000/api/cities -H 'Content-Type: application/json' -d '{"name": "Montreal"}'
curl localhost:8000/api/cities/1/weather
curl -X PUT localhost:8000/api/cities/1/notes/2026-11-09 -H 'Content-Type: application/json' -d '{"text": "Rained all afternoon"}'
curl 'localhost:8000/api/cities/1/history?from=2026-11-01'
curl localhost:8000/api/stats
```

## How the caching and history work

Each weather request asks Open-Meteo for the past 7 days plus the next 7 days. The whole response is stored as JSON in `weather_cache`, and any day up to today is upserted into `snapshots`. That table is the history, and it keeps growing the more you use the app. Requests within the next hour are answered from the cache without calling the external API.

## Project layout

```
public/index.php    front controller: serves the page, or dispatches /api/*
public/app.*        frontend (HTML, CSS, vanilla JS)
src/App.php         route table
src/Router.php      URL pattern matching, 404/405, error handling
src/Http.php        Request, Response, HttpException
src/Handlers.php    endpoint logic and SQL
src/Weather.php     Open-Meteo client + WMO weather-code descriptions
src/Database.php    PDO/SQLite connection
schema.sql          tables, constraints, index
tests/run.php       offline test suite
```
