<?php

namespace App\Services;

/** Отправка сообщения в брокер. При любой неудаче бросает исключение. */
interface MessagePublisher
{
    /**
     * @param string $topic
     * @param string $messageKey ключ сообщения (по нему получатель отсекает повторы)
     * @param array  $payload
     * @throws \Throwable брокер недоступен или не подтвердил приём
     */
    public function publish($topic, $messageKey, array $payload);
}
