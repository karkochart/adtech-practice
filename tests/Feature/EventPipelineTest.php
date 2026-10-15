<?php

namespace Tests\Feature;

use App\Services\EventBatchWorker;
use App\Services\EventIngestor;
use App\Services\EventKeys;
use App\Services\StatsAggregator;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ЗАДАЧА 4 (приём событий через Redis-очередь и воркер с батчами).
 *   App\Services\EventIngestor::ingest(array $event): bool
 *   App\Services\EventBatchWorker::drain(int $batchSize = 500): int
 * Ключи Redis - константы в App\Services\EventKeys. Нужен Redis (make up), тесты работают в базе 15.
 *
 * Событие: ['event_id' => 'abc', 'campaign_id' => 7, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120]
 *
 * ingest():
 *  - валидирует событие: event_id - непустая строка до 64 символов; campaign_id - положительное int;
 *    type - impression|click; at - 'Y-m-d H:i:s'; cost - int >= 0. Иначе \InvalidArgumentException,
 *    и в Redis НЕ остаётся ничего (в том числе ключа дедупликации)
 *  - дедупликация по event_id: повтор -> false и в очередь не попадает; новое -> JSON в очередь, true
 *  - ключ дедупликации - атомарно (SET ... EX ... NX), с TTL EventKeys::SEEN_TTL
 *
 * drain($batchSize):
 *  - забирает не больше $batchSize событий в порядке поступления (FIFO) и ОДНИМ вызовом отдаёт их
 *    StatsAggregator::flush(array $events); возвращает число обработанных; пустая очередь -> 0, flush не вызывается
 *  - надёжность: события не теряются, если flush упал (исключение пробрасывается, события остаются и
 *    обработаются при следующем drain ровно один раз); пачка переносится в EventKeys::PROCESSING и
 *    подтверждается (удаляется оттуда) только после успешного flush
 *  - если от упавшего воркера остались события в PROCESSING - они обрабатываются ПЕРВЫМИ (один воркер)
 */
class EventPipelineTest extends TestCase
{
    /** @var array[] батчи, которые получил агрегатор */
    private $batches = [];

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushdb();
        $this->batches = [];
        $this->mock(StatsAggregator::class, function ($mock) {
            $mock->shouldReceive('flush')->andReturnUsing(function (array $events) {
                $this->batches[] = $events;

                return count($events);
            });
        });
    }

    protected function tearDown(): void
    {
        Redis::flushdb();
        parent::tearDown();
    }

    private function event($id, array $override = [])
    {
        return array_merge([
            'event_id' => $id, 'campaign_id' => 7, 'type' => 'impression',
            'at' => '2026-10-15 12:30:00', 'cost' => 120,
        ], $override);
    }

    private function ingestor()
    {
        return app(EventIngestor::class);
    }

    private function worker()
    {
        return app(EventBatchWorker::class);
    }

    private function ids(array $events)
    {
        return array_map(function ($e) {
            return $e['event_id'];
        }, $events);
    }

    // ---- ingest ----

    public function test_valid_event_is_queued_as_json()
    {
        $this->assertTrue($this->ingestor()->ingest($this->event('e1')));

        $this->assertSame(1, (int) Redis::llen(EventKeys::QUEUE));
        $this->assertSame($this->event('e1'), json_decode(Redis::lindex(EventKeys::QUEUE, 0), true));
    }

    public function test_duplicate_event_id_is_dropped()
    {
        $this->assertTrue($this->ingestor()->ingest($this->event('e1')));
        $this->assertFalse($this->ingestor()->ingest($this->event('e1', ['cost' => 999])));

        $this->assertSame(1, (int) Redis::llen(EventKeys::QUEUE));
    }

    public function test_dedupe_key_has_a_ttl()
    {
        $this->ingestor()->ingest($this->event('e1'));

        $ttl = (int) Redis::ttl(EventKeys::SEEN_PREFIX . 'e1');
        $this->assertGreaterThan(0, $ttl, 'ключ дедупликации должен истекать, иначе Redis разрастётся');
        $this->assertLessThanOrEqual(EventKeys::SEEN_TTL, $ttl);
    }

    /** @dataProvider invalidEvents */
    public function test_invalid_event_is_rejected_and_leaves_no_trace(array $override, $removeKey = null)
    {
        $event = $this->event('bad-1', $override);
        if ($removeKey !== null) {
            unset($event[$removeKey]);
        }

        try {
            $this->ingestor()->ingest($event);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
        }

        $this->assertSame(0, (int) Redis::llen(EventKeys::QUEUE));
        $this->assertSame(0, (int) Redis::exists(EventKeys::SEEN_PREFIX . 'bad-1'), 'невалидное событие не должно "сжигать" ключ дедупликации');
    }

    public function invalidEvents()
    {
        return [
            'пустой event_id' => [['event_id' => '']],
            'нет event_id' => [[], 'event_id'],
            'слишком длинный id' => [['event_id' => str_repeat('x', 65)]],
            'неизвестный тип' => [['type' => 'view']],
            'campaign_id не int' => [['campaign_id' => '7']],
            'campaign_id <= 0' => [['campaign_id' => 0]],
            'отрицательная цена' => [['cost' => -1]],
            'цена не int' => [['cost' => 1.5]],
            'плохая дата' => [['at' => 'вчера']],
            'нет даты' => [[], 'at'],
        ];
    }

    // ---- drain ----

    public function test_drain_takes_batches_in_fifo_order()
    {
        foreach (['e1', 'e2', 'e3', 'e4', 'e5'] as $id) {
            $this->ingestor()->ingest($this->event($id));
        }

        $this->assertSame(3, $this->worker()->drain(3));
        $this->assertSame(2, $this->worker()->drain(3));
        $this->assertSame(0, $this->worker()->drain(3));

        $this->assertCount(2, $this->batches, 'каждый непустой drain - один вызов flush');
        $this->assertSame(['e1', 'e2', 'e3'], $this->ids($this->batches[0]));
        $this->assertSame(['e4', 'e5'], $this->ids($this->batches[1]));
    }

    public function test_drain_on_empty_queue_does_nothing()
    {
        $this->assertSame(0, $this->worker()->drain(100));
        $this->assertSame([], $this->batches);
    }

    public function test_successful_drain_acknowledges_the_batch()
    {
        $this->ingestor()->ingest($this->event('e1'));

        $this->worker()->drain(10);

        $this->assertSame(0, (int) Redis::llen(EventKeys::QUEUE));
        $this->assertSame(0, (int) Redis::llen(EventKeys::PROCESSING), 'после flush пачка должна быть подтверждена');
    }

    public function test_failed_flush_loses_nothing_and_retry_processes_exactly_once()
    {
        $failing = true;
        $this->mock(StatsAggregator::class, function ($mock) use (&$failing) {
            $mock->shouldReceive('flush')->andReturnUsing(function (array $events) use (&$failing) {
                if ($failing) {
                    throw new \RuntimeException('db is down');
                }
                $this->batches[] = $events;

                return count($events);
            });
        });
        foreach (['e1', 'e2', 'e3'] as $id) {
            $this->ingestor()->ingest($this->event($id));
        }

        try {
            $this->worker()->drain(10);
            $this->fail('exception from flush must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('db is down', $e->getMessage());
        }

        $failing = false;
        $this->assertSame(3, $this->worker()->drain(10));
        $this->assertCount(1, $this->batches);
        $this->assertSame(['e1', 'e2', 'e3'], $this->ids($this->batches[0]));
        $this->assertSame(0, $this->worker()->drain(10), 'повторно не обрабатывается');
    }

    public function test_events_left_in_processing_by_a_crashed_worker_are_processed_first()
    {
        // воркер упал после переноса пачки в PROCESSING; новые элементы кладутся в голову (LPUSH),
        // поэтому старейшее (old-1) попадает туда первым и оказывается в хвосте
        Redis::lpush(EventKeys::PROCESSING, json_encode($this->event('old-1')));
        Redis::lpush(EventKeys::PROCESSING, json_encode($this->event('old-2')));
        $this->ingestor()->ingest($this->event('new-1'));

        $this->assertSame(3, $this->worker()->drain(10));

        $this->assertSame(['old-1', 'old-2', 'new-1'], $this->ids($this->batches[0]));
        $this->assertSame(0, (int) Redis::llen(EventKeys::PROCESSING));
    }
}
