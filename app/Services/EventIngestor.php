<?php

namespace App\Services;

class EventIngestor
{
    /**
     * @param array $event ['event_id' => 'abc', 'campaign_id' => 7, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120]
     * @return bool true - событие принято и поставлено в очередь, false - дубль
     * @throws \InvalidArgumentException невалидное событие
     */
    public function ingest(array $event)
    {
        throw new \RuntimeException('TODO: задача 4, условия в tests/Feature/EventPipelineTest.php');
    }
}
