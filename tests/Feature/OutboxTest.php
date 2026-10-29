<?php

namespace Tests\Feature;

use App\Exceptions\BudgetExceededException;
use App\Services\BillingConsumer;
use App\Services\MessagePublisher;
use App\Services\Outbox;
use App\Services\OutboxCharger;
use App\Services\OutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCampaigns;
use Tests\Support\RecordingPublisher;
use Tests\TestCase;

/**
 * ЗАДАЧА 5 (transactional outbox). PHP 7.3: без типизированных свойств, стрелочных функций, match.
 * Таблицы outbox и billing_entries создаёт миграция 2026_10_29_000000 (схема - в ней).
 *
 * Контекст: после списания бюджета надо уведомить сервис биллинга. Писать в брокер прямо из транзакции нельзя
 * (откат оставит «фантомное» сообщение), после коммита - тоже (упали между коммитом и отправкой - сообщение потеряно).
 * Решение: сообщение пишется в таблицу outbox В ТОЙ ЖЕ транзакции, а отдельный процесс (relay) отправляет его в брокер.
 *
 * 1. Outbox::add($topic, $messageKey, array $payload): bool
 *    - добавляет строку (payload - JSON, sent_at - NULL); true. Ключ уже есть - ничего не добавляет, false (не ошибка)
 *    - своей транзакции не открывает
 * 2. OutboxCharger::charge($campaignId, $impressionId, $cost): Charge
 *    - списывает через BudgetService и в той же транзакции добавляет в outbox сообщение:
 *      topic 'charge.created', ключ 'charge:' . $impressionId, payload ['impression_id', 'campaign_id', 'cost']
 *    - списание и сообщение либо оба есть, либо обоих нет (любая ошибка - откат всего)
 *    - повтор того же показа: прежний Charge, второго сообщения нет
 *    - исключения BudgetService (бюджет, неактивная кампания, неверная сумма и т.д.) пробрасываются как есть
 * 3. OutboxRelay::publishPending($batchSize = 100): int
 *    - берёт неотправленные (sent_at IS NULL) по возрастанию id, не больше $batchSize, отправляет через MessagePublisher
 *      (topic, message_key, payload как массив) и ставит sent_at. Возвращает число отправленных
 *    - сообщение помечается отправленным только ПОСЛЕ успешной публикации
 *    - публикация упала: исключение пробрасывается, уже отправленные до сбоя остаются помеченными, остальные - нет
 *    - доставка at-least-once: если публикация прошла, а пометить не успели, сообщение уйдёт повторно
 *    - несколько relay одновременно не отправляют одно и то же сообщение (см. OutboxConcurrencyTest)
 * 4. BillingConsumer::handle($messageKey, array $payload): bool
 *    - пишет в billing_entries (message_key, campaign_id, amount = cost); повтор того же ключа: false, второй записи нет
 */
class OutboxTest extends TestCase
{
    use RefreshDatabase, MakesCampaigns;

    private function publisher()
    {
        $p = new RecordingPublisher();
        $this->app->instance(MessagePublisher::class, $p);

        return $p;
    }

    private function relay()
    {
        return app(OutboxRelay::class);
    }

    /** Кладёт сообщения напрямую, в обход Outbox::add, чтобы тесты relay не зависели от остального. */
    private function pending($count, $prefix = 'm')
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('outbox')->insert([
                'message_key' => "$prefix$i",
                'topic' => 'test.topic',
                'payload' => json_encode(['n' => $i]),
            ]);
        }
    }

    private function sentKeys()
    {
        return DB::table('outbox')->whereNotNull('sent_at')->orderBy('id')->pluck('message_key')->all();
    }

    // ---------- Outbox::add ----------

    public function test_add_writes_a_pending_row()
    {
        $added = app(Outbox::class)->add('charge.created', 'k-1', ['a' => 1, 'b' => 'x']);

        $this->assertTrue($added);
        $row = DB::table('outbox')->first();
        $this->assertSame('charge.created', $row->topic);
        $this->assertSame('k-1', $row->message_key);
        $this->assertEquals(['a' => 1, 'b' => 'x'], json_decode($row->payload, true));
        $this->assertNull($row->sent_at);
    }

    public function test_add_with_existing_key_returns_false_and_adds_nothing()
    {
        $outbox = app(Outbox::class);
        $outbox->add('t', 'k-1', ['v' => 1]);

        $this->assertFalse($outbox->add('t', 'k-1', ['v' => 2]));
        $this->assertSame(1, DB::table('outbox')->count());
    }

    // ---------- OutboxCharger ----------

    public function test_charge_writes_charge_and_one_outbox_message()
    {
        $c = $this->makeCampaign(1000);

        $charge = app(OutboxCharger::class)->charge($c->id, 'imp-1', 100);

        $this->assertSame('imp-1', $charge->impression_id);
        $this->assertSame(100, (int) $c->fresh()->spent_today);
        $this->assertSame(1, DB::table('outbox')->count());
        $row = DB::table('outbox')->first();
        $this->assertSame('charge.created', $row->topic);
        $this->assertSame('charge:imp-1', $row->message_key);
        $this->assertEquals(
            ['impression_id' => 'imp-1', 'campaign_id' => $c->id, 'cost' => 100],
            json_decode($row->payload, true)
        );
        $this->assertNull($row->sent_at);
    }

    public function test_repeated_charge_does_not_duplicate_the_message()
    {
        $c = $this->makeCampaign(1000);
        $first = app(OutboxCharger::class)->charge($c->id, 'imp-1', 100);

        $second = app(OutboxCharger::class)->charge($c->id, 'imp-1', 100);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(100, (int) $c->fresh()->spent_today);
        $this->assertSame(1, DB::table('charges')->count());
        $this->assertSame(1, DB::table('outbox')->count());
    }

    public function test_budget_exceeded_leaves_no_charge_and_no_message()
    {
        $c = $this->makeCampaign(100);

        try {
            app(OutboxCharger::class)->charge($c->id, 'imp-1', 101);
            $this->fail('ожидали BudgetExceededException');
        } catch (BudgetExceededException $e) {
            $this->assertSame(0, (int) $c->fresh()->spent_today);
            $this->assertSame(0, DB::table('charges')->count());
            $this->assertSame(0, DB::table('outbox')->count());
        }
    }

    public function test_invalid_cost_is_rejected_without_a_message()
    {
        $c = $this->makeCampaign(1000);

        try {
            app(OutboxCharger::class)->charge($c->id, 'imp-1', '10');
            $this->fail('ожидали InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, DB::table('outbox')->count());
        }
    }

    /** Главный смысл outbox: нет списания без сообщения. Если запись в outbox упала, откатывается и списание. */
    public function test_failed_outbox_write_rolls_back_the_charge()
    {
        $c = $this->makeCampaign(1000);
        $this->app->instance(Outbox::class, new class extends Outbox {
            public function add($topic, $messageKey, array $payload)
            {
                throw new \RuntimeException('outbox write failed');
            }
        });

        try {
            app(OutboxCharger::class)->charge($c->id, 'imp-1', 100);
            $this->fail('ожидали RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('outbox write failed', $e->getMessage());
            $this->assertSame(0, (int) $c->fresh()->spent_today);
            $this->assertSame(0, DB::table('charges')->count());
        }
    }

    // ---------- OutboxRelay ----------

    public function test_relay_publishes_in_order_and_marks_sent()
    {
        $p = $this->publisher();
        $this->pending(3);

        $count = $this->relay()->publishPending();

        $this->assertSame(3, $count);
        $this->assertSame(['m1', 'm2', 'm3'], $p->keys());
        $this->assertSame(['m1', 'm2', 'm3'], $this->sentKeys());
        $this->assertSame('test.topic', $p->delivered[0]['topic']);
        $this->assertEquals(['n' => 1], $p->delivered[0]['payload']);
    }

    public function test_relay_does_not_resend_sent_messages()
    {
        $p = $this->publisher();
        $this->pending(2);
        $this->relay()->publishPending();

        $this->assertSame(0, $this->relay()->publishPending());
        $this->assertSame(['m1', 'm2'], $p->keys());
    }

    public function test_relay_respects_batch_size()
    {
        $p = $this->publisher();
        $this->pending(5);

        $this->assertSame(2, $this->relay()->publishPending(2));
        $this->assertSame(['m1', 'm2'], $p->keys());
        $this->assertSame(3, $this->relay()->publishPending(10));
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $p->keys());
    }

    public function test_relay_with_empty_outbox_does_nothing()
    {
        $p = $this->publisher();

        $this->assertSame(0, $this->relay()->publishPending());
        $this->assertSame(0, $p->calls);
    }

    public function test_publish_failure_keeps_earlier_messages_sent_and_the_rest_pending()
    {
        $p = $this->publisher();
        $p->failOnCall = [3];
        $this->pending(5);

        try {
            $this->relay()->publishPending(10);
            $this->fail('ожидали исключение брокера');
        } catch (\RuntimeException $e) {
            $this->assertSame('broker is down', $e->getMessage());
        }

        $this->assertSame(['m1', 'm2'], $p->keys());
        $this->assertSame(['m1', 'm2'], $this->sentKeys());

        // брокер ожил: недоставленные уходят, ничего не потеряно и ничего не продублировано
        $this->assertSame(3, $this->relay()->publishPending(10));
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $p->keys());
    }

    public function test_message_with_lost_ack_is_delivered_again_not_lost()
    {
        $p = $this->publisher();
        $p->loseAckOnCall = [2];   // m2 доставлено, но брокер «не ответил»
        $this->pending(3);

        try {
            $this->relay()->publishPending(10);
            $this->fail('ожидали исключение');
        } catch (\RuntimeException $e) {
            $this->assertSame('ack lost', $e->getMessage());
        }
        $this->assertSame(['m1'], $this->sentKeys(), 'm2 не подтверждено, значит не помечено');

        $this->relay()->publishPending(10);

        $this->assertSame(['m1', 'm2', 'm2', 'm3'], $p->keys(), 'at-least-once: m2 пришло дважды, но не потеряно');
        $this->assertSame(['m1', 'm2', 'm3'], $this->sentKeys());
    }

    // ---------- BillingConsumer ----------

    public function test_consumer_records_the_entry_once()
    {
        $consumer = app(BillingConsumer::class);
        $payload = ['impression_id' => 'imp-1', 'campaign_id' => 7, 'cost' => 120];

        $this->assertTrue($consumer->handle('charge:imp-1', $payload));
        $this->assertFalse($consumer->handle('charge:imp-1', $payload));

        $this->assertSame(1, DB::table('billing_entries')->count());
        $entry = DB::table('billing_entries')->first();
        $this->assertSame('charge:imp-1', $entry->message_key);
        $this->assertSame(7, (int) $entry->campaign_id);
        $this->assertSame(120, (int) $entry->amount);
    }

    // ---------- Все части вместе ----------

    /**
     * 30 списаний -> outbox -> relay -> «брокер», который то недоступен, то теряет подтверждение -> потребитель.
     * Сообщения приходят повторно, но итог в биллинге точный: каждое списание ровно один раз.
     */
    public function test_flaky_broker_still_gives_exact_billing()
    {
        $c = $this->makeCampaign(1000000);
        $charger = app(OutboxCharger::class);
        $expected = 0;
        for ($i = 1; $i <= 30; $i++) {
            $cost = 10 + $i;
            $charger->charge($c->id, "imp-$i", $cost);
            $expected += $cost;
        }

        $consumer = app(BillingConsumer::class);
        $p = $this->publisher();
        $p->failOnCall = [4, 11, 12];
        $p->loseAckOnCall = [2, 7, 15, 16, 25];
        $p->onDeliver = function ($topic, $key, array $payload) use ($consumer) {
            $consumer->handle($key, $payload);
        };

        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                if ($this->relay()->publishPending(7) === 0) {
                    break;
                }
            } catch (\RuntimeException $e) {
                // брокер подвёл: relay запустят снова
            }
        }

        $this->assertSame(0, DB::table('outbox')->whereNull('sent_at')->count(), 'всё отправлено');
        $this->assertGreaterThan(30, count($p->delivered), 'повторы были: брокер терял подтверждения');
        $this->assertSame(30, DB::table('billing_entries')->count());
        $this->assertSame($expected, (int) DB::table('billing_entries')->sum('amount'));
    }
}
