<?php

namespace Tests\Support;

use App\Services\MessagePublisher;

/** Тестовый «брокер»: запоминает доставленное и умеет ломаться в заданные вызовы. */
class RecordingPublisher implements MessagePublisher
{
    /** @var array[] доставленные сообщения: ['topic' =>, 'key' =>, 'payload' =>] */
    public $delivered = [];

    /** @var int[] номера вызовов (с 1), на которых брокер недоступен: сообщение НЕ доставляется */
    public $failOnCall = [];

    /** @var int[] номера вызовов, на которых сообщение доставляется, но подтверждение теряется (исключение) */
    public $loseAckOnCall = [];

    /** @var callable|null вызывается при доставке: function ($topic, $key, array $payload) */
    public $onDeliver;

    /** @var int пауза на каждую доставку, микросекунды */
    public $delayMicros = 0;

    public $calls = 0;

    public function publish($topic, $messageKey, array $payload)
    {
        $this->calls++;
        if (in_array($this->calls, $this->failOnCall, true)) {
            throw new \RuntimeException('broker is down');
        }
        if ($this->delayMicros > 0) {
            usleep($this->delayMicros);
        }
        $this->delivered[] = ['topic' => $topic, 'key' => $messageKey, 'payload' => $payload];
        if ($this->onDeliver !== null) {
            call_user_func($this->onDeliver, $topic, $messageKey, $payload);
        }
        if (in_array($this->calls, $this->loseAckOnCall, true)) {
            throw new \RuntimeException('ack lost');
        }
    }

    public function keys()
    {
        return array_column($this->delivered, 'key');
    }
}
