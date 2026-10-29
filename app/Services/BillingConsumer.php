<?php

namespace App\Services;

use App\Models\BillingEntry;
use Illuminate\Database\QueryException;

class BillingConsumer
{
    /**
     * Принимает сообщение о списании и записывает его в billing_entries.
     *
     * @param string $messageKey
     * @param array $payload ['campaign_id' => ..., 'cost' => ...]
     * @return bool true - записано, false - это сообщение уже было обработано
     */
    public function handle($messageKey, array $payload)
    {
        try {
            BillingEntry::create(
                [
                    'message_key' => $messageKey,
                    'campaign_id' => $payload['campaign_id'],
                    'amount' => $payload['cost'],
                ]
            );
        } catch (QueryException $e) {
            // Это сообщение уже обработано. Любая другая ошибка - не «уже было»: пусть брокер доставит сообщение повторно.
            if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
