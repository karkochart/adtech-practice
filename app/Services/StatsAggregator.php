<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StatsAggregator
{
    /** 5 полей на строку: 1000 x 5 = 5000 плейсхолдеров, предел MySQL - 65 535. */
    const CHUNK_SIZE = 1000;

    /** Сколько раз пробовать весь пакет при deadlock (1213). */
    const ATTEMPTS = 3;

    /**
     * @param array $events [['campaign_id' => 1, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120], ...]
     * @return int число записанных пар (campaign_id, час)
     */
    public function flush(array $events)
    {
        if (empty($events)) {
            return 0;
        }

        // Весь пакет проверяется до первой записи: ошибка в любом событии - ничего не пишем.
        $grouped = [];
        foreach ($events as $event) {
            $this->validate($event);
            $hour = $this->getHour($event['at']);
            $key = $event['campaign_id'] . '_' . $hour;
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['campaign_id' => $event['campaign_id'], 'hour' => $hour . ':00:00', 'clicks' => 0, 'impressions' => 0, 'spend' => 0];
            }
            $grouped[$key]['clicks'] += $event['type'] === 'click' ? 1 : 0;
            $grouped[$key]['impressions'] += $event['type'] === 'impression' ? 1 : 0;
            $grouped[$key]['spend'] += $event['cost'];
        }

        // Одинаковый порядок строк у всех воркеров - блокировки берутся в одном порядке, без deadlock'ов между ними.
        ksort($grouped);
        $chunks = array_chunk(array_values($grouped), self::CHUNK_SIZE);

        // Все части - в одной транзакции: либо пакет записан целиком, либо не записан совсем.
        // DB::transaction откатывает при любом \Throwable и повторяет пакет при deadlock.
        DB::transaction(function () use ($chunks) {
            foreach ($chunks as $chunk) {
                $this->upsertChunk($chunk);
            }
        }, self::ATTEMPTS);

        return count($grouped);
    }

    private function upsertChunk(array $rows)
    {
        $placeholders = [];
        $bindings = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $bindings[] = $row['campaign_id'];
            $bindings[] = $row['hour'];
            $bindings[] = $row['clicks'];
            $bindings[] = $row['impressions'];
            $bindings[] = $row['spend'];
        }

        // `AS new` вместо устаревшего VALUES(col) (MySQL 8.0.19+).
        DB::statement(
            'INSERT INTO hourly_stats (campaign_id, hour, clicks, impressions, spend)
             VALUES ' . implode(', ', $placeholders) . ' AS new
             ON DUPLICATE KEY UPDATE
                 clicks = hourly_stats.clicks + new.clicks,
                 impressions = hourly_stats.impressions + new.impressions,
                 spend = hourly_stats.spend + new.spend',
            $bindings
        );
    }

    private function validate($event)
    {
        if (!is_array($event)) {
            throw new \InvalidArgumentException('Event must be an array');
        }
        if (!isset($event['type']) || !in_array($event['type'], ['impression', 'click'], true)) {
            throw new \InvalidArgumentException('Unknown event type: ' . var_export($event['type'] ?? null, true));
        }
        if (!isset($event['campaign_id']) || !is_int($event['campaign_id']) || $event['campaign_id'] <= 0) {
            throw new \InvalidArgumentException('Invalid campaign_id: ' . var_export($event['campaign_id'] ?? null, true));
        }
        if (!isset($event['cost']) || !is_int($event['cost']) || $event['cost'] < 0) {
            throw new \InvalidArgumentException('Invalid cost: ' . var_export($event['cost'] ?? null, true));
        }
    }

    /** '2026-10-15 10:37:12' -> '2026-10-15 10'. Строго формат Y-m-d H:i:s, без «исправлений» вроде 30 февраля -> 2 марта. */
    private function getHour($input)
    {
        if (!is_string($input) || $input === '') {
            throw new \InvalidArgumentException('Invalid date format: ' . var_export($input, true));
        }

        $dateTime = \DateTime::createFromFormat('!Y-m-d H:i:s', $input);
        $errors = \DateTime::getLastErrors();

        if (
            $dateTime === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format('Y-m-d H:i:s') !== $input
        ) {
            throw new \InvalidArgumentException('Invalid date format: ' . $input);
        }

        return $dateTime->format('Y-m-d H');
    }
}
