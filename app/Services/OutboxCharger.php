<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($campaignId, $impressionId, $cost) {
            $charge = $this->budget->charge($campaignId, $impressionId, $cost);
            $this->outbox->add('charge.created', "charge:$impressionId", [
                'impression_id' => $charge->impression_id,
                'campaign_id' => (int) $charge->campaign_id,
                'cost' => (int) $charge->cost,
            ]);

            return $charge;
        });
    }
}
