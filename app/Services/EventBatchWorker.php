<?php

namespace App\Services;

class EventBatchWorker
{
    private $aggregator;

    public function __construct(StatsAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * Забирает до $batchSize событий из очереди и отдаёт их агрегатору одним вызовом flush().
     *
     * @param int $batchSize
     * @return int число обработанных событий
     */
    public function drain($batchSize = 500)
    {
        throw new \RuntimeException('TODO: задача 4, условия в tests/Feature/EventPipelineTest.php');
    }
}
