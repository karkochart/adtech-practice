<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Надёжная выборка из очереди. Расположение данных:
 *   QUEUE      - новые события: продюсер LPUSH в голову, воркер забирает с хвоста (FIFO);
 *   PROCESSING - пачка, которую воркер уже взял, но ещё не подтвердил. Туда переносят RPOPLPUSH, то есть
 *                голова - самое новое событие, хвост - самое старое.
 *
 * Пока пачка лежит в PROCESSING, она не потеряется ни при исключении из flush(), ни при гибели процесса (kill -9):
 * следующий drain() первым делом берёт содержимое PROCESSING. Отдельного «возврата в очередь» не нужно.
 *
 * Гарантия - at-least-once: если процесс умрёт после успешного flush(), но до подтверждения, пачка уйдёт
 * в flush() повторно. Поэтому агрегатор должен выдерживать повтор (например, учёт обработанных batch_id).
 * Воркер предполагается один: два воркера делили бы один PROCESSING.
 */
class EventBatchWorker
{
    /** KEYS[1] - очередь, KEYS[2] - PROCESSING; ARGV[1] - сколько событий перенести. */
    const MOVE_SCRIPT = <<<'LUA'
        for i = 1, tonumber(ARGV[1]) do
            if not redis.call('RPOPLPUSH', KEYS[1], KEYS[2]) then
                break
            end
        end
        return 1
        LUA;

    private $aggregator;

    public function __construct(StatsAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * Забирает до $batchSize событий (сначала остатки в PROCESSING, потом новые) и отдаёт агрегатору одним вызовом flush().
     *
     * @param int $batchSize
     * @return int число обработанных событий
     */
    public function drain($batchSize = 500)
    {
        $batchSize = (int) $batchSize;
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be positive');
        }

        // Добираем из очереди до $batchSize вместе с остатками; перенос атомарный (RPOPLPUSH), одним запросом.
        $free = max(0, $batchSize - (int) Redis::llen(EventKeys::PROCESSING));
        if ($free > 0) {
            Redis::eval(self::MOVE_SCRIPT, 2, EventKeys::QUEUE, EventKeys::PROCESSING, $free);
        }

        // Берём самые старые $batchSize событий: хвост списка, читаем от старого к новому.
        $raw = array_reverse(Redis::lrange(EventKeys::PROCESSING, -$batchSize, -1));
        if (empty($raw)) {
            return 0;
        }
        $events = array_map(function ($json) {
            return json_decode($json, true);
        }, $raw);

        // Исключение из flush() пробрасывается, пачка остаётся в PROCESSING и уйдёт при следующем drain().
        $this->aggregator->flush($events);

        // Подтверждение: убираем из хвоста ровно столько, сколько обработали.
        Redis::ltrim(EventKeys::PROCESSING, 0, -(count($events) + 1));

        return count($events);
    }
}
