<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

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
        $publishedCount = 0;

        while ($publishedCount < $batchSize) {
            $published = DB::transaction(function () {
                $row = DB::table('outbox')
                    ->whereNull('sent_at')
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if (!$row) {
                    return false;
                }

                $this->publisher->publish(
                    $row->topic,
                    $row->message_key,
                    json_decode($row->payload, true)
                );

                DB::table('outbox')
                    ->where('id', $row->id)
                    ->update(['sent_at' => now()]);

                return true;
            });

            if (!$published) {
                break;
            }

            $publishedCount++;
        }

        return $publishedCount;
    }
}
