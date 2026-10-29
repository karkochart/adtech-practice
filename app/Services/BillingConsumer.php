<?php

namespace App\Services;

class BillingConsumer
{
    /**
     * Принимает сообщение о списании и записывает его в billing_entries.
     *
     * @param string $messageKey
     * @param array  $payload ['impression_id' => ..., 'campaign_id' => ..., 'cost' => ...]
     * @return bool true - записано, false - это сообщение уже было обработано
     */
    public function handle($messageKey, array $payload)
    {
        throw new \RuntimeException('TODO: задача 5, условия в tests/Feature/OutboxTest.php');
    }
}
