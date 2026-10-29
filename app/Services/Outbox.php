<?php

namespace App\Services;

use App\Models\Outbox as OutboxModel;
use Illuminate\Database\QueryException;

class Outbox
{
    /**
     * Кладёт сообщение в таблицу outbox. Своей транзакции не открывает: вызывается внутри транзакции вызывающего кода.
     *
     * @param string $topic
     * @param string $messageKey ключ дедупликации (уникален)
     * @param array $payload сохраняется как JSON
     * @return bool true - добавлено, false - сообщение с таким ключом уже есть (повтор - не ошибка)
     */
    public function add($topic, $messageKey, array $payload)
    {
        try {
            OutboxModel::create([
                'topic' => $topic,
                'message_key' => $messageKey,
                'payload' => json_encode($payload),
            ]);
        } catch (QueryException $e) {
            // Повтор ключа - не ошибка; любая другая ошибка БД должна дойти до вызывающего и откатить транзакцию.
            if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                return false;
            }

            throw $e;
        }

        return true;
    }
}
