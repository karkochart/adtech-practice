<?php

namespace App\Services;

class OutboxRelay
{
    private $publisher;

    public function __construct(MessagePublisher $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Отправляет неотправленные сообщения из outbox в брокер.
     *
     * @param int $batchSize не больше стольких сообщений за вызов
     * @return int сколько сообщений отправлено и помечено
     * @throws \Throwable если публикация не удалась (уже отправленные до сбоя остаются помеченными)
     */
    public function publishPending($batchSize = 100)
    {
        throw new \RuntimeException('TODO: задача 5, условия в tests/Feature/OutboxTest.php');
    }
}
