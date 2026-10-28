<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class EventIngestor
{
    /**
     * Ключ дедупликации и постановка в очередь - один Lua-скрипт, поэтому между ними ничего не вклинится:
     * ни параллельный ingest с тем же event_id, ни сбой, после которого ключ остался бы, а события в очереди нет.
     * KEYS[1] - ключ дедупликации, KEYS[2] - очередь; ARGV[1] - событие (JSON), ARGV[2] - TTL ключа.
     */
    const ENQUEUE_SCRIPT = <<<'LUA'
        if redis.call('SET', KEYS[1], 1, 'NX', 'EX', ARGV[2]) then
            redis.call('LPUSH', KEYS[2], ARGV[1])
            return 1
        end
        return 0
        LUA;

    /**
     * @param array $event ['event_id' => 'abc', 'campaign_id' => 7, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120]
     * @return bool true - событие принято и поставлено в очередь, false - дубль
     * @throws \InvalidArgumentException невалидное событие
     */
    public function ingest(array $event)
    {
        $this->validate($event);

        $json = json_encode($event);
        if ($json === false) {
            throw new \InvalidArgumentException('Event cannot be encoded as JSON: ' . json_last_error_msg());
        }

        return (bool) Redis::eval(
            self::ENQUEUE_SCRIPT, 2, EventKeys::SEEN_PREFIX . $event['event_id'], EventKeys::QUEUE, $json, EventKeys::SEEN_TTL
        );
    }

    private function validate(array $event)
    {
        if (!isset($event['event_id']) || !is_string($event['event_id']) || $event['event_id'] === '' || strlen($event['event_id']) > 64) {
            throw new \InvalidArgumentException('Invalid event_id');
        }
        if (!isset($event['campaign_id']) || !is_int($event['campaign_id']) || $event['campaign_id'] <= 0) {
            throw new \InvalidArgumentException('Invalid campaign_id');
        }
        // Третий аргумент true обязателен: в PHP 7.3 in_array(0, ['impression', 'click']) даёт true (0 == 'impression').
        if (!isset($event['type']) || !in_array($event['type'], ['impression', 'click'], true)) {
            throw new \InvalidArgumentException('Invalid type');
        }
        if (!isset($event['at']) || !$this->isRealDateTime($event['at'])) {
            throw new \InvalidArgumentException('Invalid at');
        }
        if (!isset($event['cost']) || !is_int($event['cost']) || $event['cost'] < 0) {
            throw new \InvalidArgumentException('Invalid cost');
        }
    }

    /** createFromFormat «чинит» несуществующие даты (30 февраля -> 2 марта), поэтому сравниваем обратное форматирование. */
    private function isRealDateTime($value)
    {
        if (!is_string($value)) {
            return false;
        }
        $dateTime = \DateTime::createFromFormat('!Y-m-d H:i:s', $value);

        return $dateTime !== false && $dateTime->format('Y-m-d H:i:s') === $value;
    }
}
