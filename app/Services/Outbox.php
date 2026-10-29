<?php

namespace App\Services;

class Outbox
{
    /**
     * Кладёт сообщение в таблицу outbox. Своей транзакции не открывает: вызывается внутри транзакции вызывающего кода.
     *
     * @param string $topic
     * @param string $messageKey ключ дедупликации (уникален)
     * @param array  $payload    сохраняется как JSON
     * @return bool true - добавлено, false - сообщение с таким ключом уже есть (повтор - не ошибка)
     */
    public function add($topic, $messageKey, array $payload)
    {
        throw new \RuntimeException('TODO: задача 5, условия в tests/Feature/OutboxTest.php');
    }
}
