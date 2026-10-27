<?php

namespace App\Services;

use App\Exceptions\BudgetExceededException;
use App\Exceptions\CampaignInactiveException;
use App\Exceptions\ImpressionConflictException;
use App\Models\Campaign;
use App\Models\Charge;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * @param int $campaignId
     * @param string $impressionId уникальный id показа (идемпотентность)
     * @param int $cost микро-единицы, положительное целое
     * @return Charge
     * @throws BudgetExceededException
     * @throws CampaignInactiveException
     * @throws ImpressionConflictException
     */
    public function charge($campaignId, $impressionId, $cost)
    {
        if ($cost !== (int)$cost || $cost <= 0) {
            throw new \InvalidArgumentException('Cost must be a positive integer');
        }
        DB::beginTransaction();
        try {
            $campaign = Campaign::where('id', $campaignId)
                ->lockForUpdate()
                ->first();

            if (!$campaign) {
                throw new ModelNotFoundException("Campaign not found");
            }
            if ($campaign->status !== 'active') {
                throw new CampaignInactiveException("Campaign is not active");
            }
            $charge = Charge::where('impression_id', $impressionId)->first();
            if ($charge) {
                if ($charge->cost != $cost || $charge->campaign_id != $campaignId) {
                    throw new ImpressionConflictException("Impression conflict");
                } else {
                    DB::rollBack();
                    return $charge; // идемпотентность: тот же показ, та же кампания, та же сумма
                }
            }
            if ($campaign->spent_today + $cost > $campaign->daily_budget) {
                throw new BudgetExceededException("Budget exceeded");
            }
            $charge = Charge::create([
                'impression_id' => $impressionId,
                'campaign_id' => $campaignId,
                'cost' => $cost
            ]);
            $campaign->spent_today += $cost;
            $charge->save();
            $campaign->save();

            DB::commit();
            return $charge;
        } catch (QueryException $e) {
            DB::rollBack();
            // 1062 - дубликат impression_id: параллельный запрос (на другую кампанию, общей блокировки нет)
            // успел вставить первым. Транзакция уже откачена - смотрим, что записал победитель.
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
                $winner = Charge::where('impression_id', $impressionId)->first();
                if ($winner && $winner->campaign_id == $campaignId && $winner->cost == $cost) {
                    return $winner;   // тот же показ - идемпотентно возвращаем его
                }
                throw new ImpressionConflictException("Impression conflict");
            }
            throw $e;   // любая другая ошибка базы (deadlock, обрыв соединения, CHECK, слишком длинное значение) - наружу
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
