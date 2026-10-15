<?php

namespace App\Services;

class BudgetService
{
    /**
     * @param int    $campaignId
     * @param string $impressionId уникальный id показа (идемпотентность)
     * @param int    $cost         микро-единицы, положительное целое
     * @return \App\Models\Charge
     * @throws \App\Exceptions\BudgetExceededException
     * @throws \App\Exceptions\CampaignInactiveException
     * @throws \App\Exceptions\ImpressionConflictException
     */
    public function charge($campaignId, $impressionId, $cost)
    {
        throw new \RuntimeException('TODO: задача 1, условия в tests/Feature/BudgetServiceTest.php');
    }
}
