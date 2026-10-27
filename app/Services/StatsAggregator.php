<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StatsAggregator
{
    /**
     * @param array $events [['campaign_id' => 1, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120], ...]
     * @return int число записанных пар (campaign_id, час)
     */
    public function flush(array $events)
    {
        if (empty($events)) {
            return 0;
        }
        $grouped = [];
        foreach ($events as $event) {
            if (!isset($event['type']) || !in_array($event['type'], ['impression', 'click'], true)) {
                throw new \InvalidArgumentException('Unknown event type: ' . ($event['type'] ?? 'null'));
            }
            $date = $this->getHour($event['at'] ?? null);
            $key = $event['campaign_id'] . '_' . $date;
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['clicks' => 0, 'impressions' => 0, 'spend' => 0, 'campaign_id' => $event['campaign_id'], 'hour' => $date . ':00:00'];
            }
            $grouped[$key]['clicks'] += $event['type'] === 'click' ? 1 : 0;
            $grouped[$key]['impressions'] += $event['type'] === 'impression' ? 1 : 0;
            $grouped[$key]['spend'] += $event['cost'];
        }
        ksort($grouped);

        $rows = array_values($grouped);
        $chunks = array_chunk($rows, 1000);


        DB::beginTransaction();
        foreach ($chunks as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?)';

                $bindings = array_merge($bindings, [
                    $row['campaign_id'],
                    $row['hour'],
                    $row['clicks'],
                    $row['impressions'],
                    $row['spend'],
                ]);
            }

            $sql = '
                INSERT INTO hourly_stats
                    (campaign_id, hour, clicks, impressions, spend)
                VALUES ' . implode(', ', $placeholders) . '
                ON DUPLICATE KEY UPDATE
                    clicks = clicks + VALUES(clicks),
                    impressions = impressions + VALUES(impressions),
                    spend = spend + VALUES(spend)
            ';

            try {
                DB::statement($sql, $bindings);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
        DB::commit();

        return count($grouped);
    }

    private function getHour($input)
    {
        if (!is_string($input) || $input === '') {
            throw new \InvalidArgumentException(
                'Invalid date format: ' . var_export($input, true)
            );
        }

        $dateTime = \DateTime::createFromFormat('!Y-m-d H:i:s', $input);
        $errors = \DateTime::getLastErrors();

        if (
            $dateTime === false ||
            ($errors !== false && (
                    $errors['warning_count'] > 0 ||
                    $errors['error_count'] > 0
                )) ||
            $dateTime->format('Y-m-d H:i:s') !== $input
        ) {
            throw new \InvalidArgumentException('Invalid date format: ' . $input);
        }

        return $dateTime->format('Y-m-d H');
    }
}
