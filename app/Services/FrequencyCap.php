<?php

namespace App\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/**
 * Частотное ограничение: не больше $max показов пользователю по кампании за окно $windowSeconds.
 * Окно фиксированное: стартует с первого показа и не продлевается.
 */
class FrequencyCap
{
    /**
     * KEYS[1] - счётчик; ARGV[1] - окно в секундах; ARGV[2] - лимит.
     * INCR и EXPIRE выполняются одним скриптом, поэтому ключ не может остаться без TTL
     * (при отдельных add + increment ключ, истёкший между ними, создавался бы без срока жизни).
     * Условие TTL < 0 лечит и «осиротевший» ключ без TTL, если он уже есть.
     */
    const REDIS_SCRIPT = <<<'LUA'
        local n = redis.call('INCR', KEYS[1])
        if n == 1 or redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        if n <= tonumber(ARGV[2]) then return 1 end
        return 0
        LUA;

    private $max;
    private $windowSeconds;

    public function __construct($max = 3, $windowSeconds = 3600)
    {
        $this->max = $max;
        $this->windowSeconds = $windowSeconds;
    }

    /** @return bool true - показ разрешён и засчитан */
    public function allow($userId, $campaignId)
    {
        $key = "frequency_cap:{$userId}:{$campaignId}";
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            return (bool) $store->connection()->eval(
                self::REDIS_SCRIPT, 1, $store->getPrefix() . $key, $this->windowSeconds, $this->max
            );
        }

        // Другие драйверы (в тестах - array): add создаёт счётчик с TTL, только если его нет,
        // increment атомарно увеличивает и TTL не меняет. Отказы окно не продлевают.
        Cache::add($key, 0, $this->windowSeconds);

        return Cache::increment($key) <= $this->max;
    }
}
