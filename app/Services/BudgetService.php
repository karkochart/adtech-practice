<?php

namespace App\Services;

use App\Exceptions\BudgetExceededException;
use App\Exceptions\CampaignInactiveException;
use App\Exceptions\ImpressionConflictException;
use App\Models\Campaign;
use App\Models\Charge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function charge($campaignId, $impressionId, $cost)
    {
        if (!is_int($cost) || $cost <= 0) {
            throw new \InvalidArgumentException(
                'Cost must be a positive integer'
            );
        }

        try {
            return DB::transaction(function () use (
                $campaignId,
                $impressionId,
                $cost
            ) {
                $campaign = Campaign::where('id', $campaignId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Повтор уже обработанного показа
                $existing = Charge::where(
                    'impression_id',
                    $impressionId
                )->first();

                if ($existing) {
                    return $this->resolveExistingCharge(
                        $existing,
                        $campaignId,
                        $cost
                    );
                }

                if ($campaign->status !== 'active') {
                    throw new CampaignInactiveException(
                        'Campaign is not active'
                    );
                }

                if ($campaign->spent_today + $cost
                    > $campaign->daily_budget) {
                    throw new BudgetExceededException(
                        'Budget exceeded'
                    );
                }

                $charge = Charge::create([
                    'impression_id' => $impressionId,
                    'campaign_id' => $campaignId,
                    'cost' => $cost,
                ]);

                $campaign->spent_today += $cost;
                $campaign->save();

                return $charge;
            });
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            // DB::transaction уже выполнил rollback.
            $winner = Charge::where(
                'impression_id',
                $impressionId
            )->first();

            if (!$winner) {
                throw $e;
            }

            return $this->resolveExistingCharge(
                $winner,
                $campaignId,
                $cost
            );
        }
    }

    private function resolveExistingCharge(
        Charge $charge,
               $campaignId,
               $cost
    ) {
        if (
            $charge->campaign_id != $campaignId ||
            $charge->cost != $cost
        ) {
            throw new ImpressionConflictException(
                'Impression conflict'
            );
        }

        return $charge;
    }

    private function isDuplicateKey(QueryException $e)
    {
        return isset($e->errorInfo[1])
            && (int) $e->errorInfo[1] === 1062;
    }
}
