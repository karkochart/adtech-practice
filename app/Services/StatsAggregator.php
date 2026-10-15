<?php

namespace App\Services;

class StatsAggregator
{
    /**
     * @param array $events [['campaign_id' => 1, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120], ...]
     * @return int число записанных пар (campaign_id, час)
     */
    public function flush(array $events)
    {
        throw new \RuntimeException('TODO: задача 2, условия в tests/Feature/StatsAggregatorTest.php');
    }
}
