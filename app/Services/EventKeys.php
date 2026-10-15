<?php

namespace App\Services;

/** Ключи Redis конвейера событий. Используются и кодом, и тестами. */
final class EventKeys
{
    /** Очередь: продюсер делает LPUSH (в голову), воркер забирает с хвоста - получается FIFO. */
    const QUEUE = 'events:queue';

    /** Список «в обработке»: сюда воркер переносит пачку атомарно, пока она не подтверждена. */
    const PROCESSING = 'events:processing';

    /** Ключи дедупликации: events:seen:<event_id>, значение любое, TTL = SEEN_TTL секунд. */
    const SEEN_PREFIX = 'events:seen:';

    const SEEN_TTL = 86400;
}
