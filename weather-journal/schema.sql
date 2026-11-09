-- Weather Journal schema (SQLite).
-- Applied automatically on every connection; every statement is idempotent.

CREATE TABLE IF NOT EXISTS cities (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    country    TEXT,
    latitude   REAL    NOT NULL,
    longitude  REAL    NOT NULL,
    timezone   TEXT    NOT NULL DEFAULT 'auto',
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (latitude, longitude)
);

-- One row per city per day. Past days are back-filled from the API,
-- and today's row is overwritten each time fresh data is fetched.
CREATE TABLE IF NOT EXISTS snapshots (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    city_id       INTEGER NOT NULL REFERENCES cities(id) ON DELETE CASCADE,
    date          TEXT    NOT NULL,           -- YYYY-MM-DD, city-local
    temp_max      REAL,
    temp_min      REAL,
    precipitation REAL,
    weather_code  INTEGER,
    fetched_at    TEXT    NOT NULL,
    UNIQUE (city_id, date)
);

-- Your own journal entry: at most one note per city per day.
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    city_id    INTEGER NOT NULL REFERENCES cities(id) ON DELETE CASCADE,
    date       TEXT    NOT NULL,
    text       TEXT    NOT NULL CHECK (length(text) BETWEEN 1 AND 1000),
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (city_id, date)
);

-- The last API response per city, stored as JSON, so we don't call
-- the external API more than once per cache window.
CREATE TABLE IF NOT EXISTS weather_cache (
    city_id    INTEGER PRIMARY KEY REFERENCES cities(id) ON DELETE CASCADE,
    payload    TEXT    NOT NULL,              -- JSON
    fetched_at INTEGER NOT NULL               -- unix timestamp
);

CREATE INDEX IF NOT EXISTS idx_snapshots_city_date ON snapshots (city_id, date);
