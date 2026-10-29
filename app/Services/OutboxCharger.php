<?php

namespace App\Services;

class OutboxCharger
{
    private $budget;
    private $outbox;

    public function __construct(BudgetService $budget, Outbox $outbox)
    {
        $this->budget = $budget;
        $this->outbox = $outbox;
    }

    /**
     * Списывает бюджет (BudgetService) и ставит в outbox сообщение для биллинга.
     *
     * @return \App\Models\Charge
     */
    public function charge($campaignId, $impressionId, $cost)
    {
        throw new \RuntimeException('TODO: задача 5, условия в tests/Feature/OutboxTest.php');
    }
}
