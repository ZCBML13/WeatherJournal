<?php

declare(strict_types=1);

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Weather.php';
require_once __DIR__ . '/Handlers.php';

final class App
{
    /** Wire every endpoint to its handler. */
    public static function router(PDO $db, WeatherProvider $weather, ?Closure $clock = null, int $cacheTtl = 3600): Router
    {
        $h = new Handlers($db, $weather, $clock, $cacheTtl);
        $r = new Router();

        $r->add('GET', '/api/cities', $h->listCities(...));
        $r->add('POST', '/api/cities', $h->addCity(...));
        $r->add('GET', '/api/cities/{id}', $h->getCity(...));
        $r->add('DELETE', '/api/cities/{id}', $h->deleteCity(...));
        $r->add('GET', '/api/cities/{id}/weather', $h->weather(...));
        $r->add('GET', '/api/cities/{id}/history', $h->history(...));
        $r->add('GET', '/api/cities/{id}/notes', $h->listNotes(...));
        $r->add('PUT', '/api/cities/{id}/notes/{date}', $h->putNote(...));
        $r->add('DELETE', '/api/cities/{id}/notes/{date}', $h->deleteNote(...));
        $r->add('GET', '/api/stats', $h->stats(...));

        return $r;
    }
}
