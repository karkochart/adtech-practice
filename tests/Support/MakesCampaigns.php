<?php

namespace Tests\Support;

use App\Models\Campaign;

trait MakesCampaigns
{
    protected function makeCampaign($dailyBudget = 1000, $status = 'active', $spent = 0)
    {
        return Campaign::create([
            'name' => 'test-' . uniqid(),
            'daily_budget' => $dailyBudget,
            'spent_today' => $spent,
            'status' => $status,
        ]);
    }
}
