<?php

namespace Tests\Feature;

use App\Services\StatsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCampaigns;
use Tests\TestCase;

/**
 * ЗАДАЧА 2 (агрегация статистики пакетом). Реализовать App\Services\StatsAggregator::flush(array $events).
 *
 * Событие: ['campaign_id' => 1, 'type' => 'impression'|'click', 'at' => 'Y-m-d H:i:s', 'cost' => 120]
 * Результат - строки hourly_stats по (campaign_id, час): impressions, clicks, spend (сумма cost всех событий).
 *  - ВЕСЬ пакет - ОДНИМ SQL-запросом к hourly_stats: INSERT ... ON DUPLICATE KEY UPDATE (подсказка: счётчики прибавляются)
 *  - повторный flush прибавляет к уже записанному (не перезаписывает)
 *  - неизвестный type - \InvalidArgumentException, в таблицу ничего не пишем
 *  - пустой пакет - ноль запросов, возвращает 0
 *  - возвращает число записанных пар (campaign_id, час)
 */
class StatsAggregatorTest extends TestCase
{
    use RefreshDatabase, MakesCampaigns;

    private function svc()
    {
        return app(StatsAggregator::class);
    }

    private function ev($campaign, $type, $at, $cost = 0)
    {
        return ['campaign_id' => $campaign, 'type' => $type, 'at' => $at, 'cost' => $cost];
    }

    public function test_aggregates_by_campaign_and_hour()
    {
        $n = $this->svc()->flush([
            $this->ev(1, 'impression', '2026-10-15 10:05:00', 100),
            $this->ev(1, 'impression', '2026-10-15 10:59:59', 150),
            $this->ev(1, 'click', '2026-10-15 10:30:00', 0),
            $this->ev(1, 'impression', '2026-10-15 11:00:00', 70),
            $this->ev(2, 'impression', '2026-10-15 10:10:00', 10),
        ]);

        $this->assertSame(3, $n);
        $row = DB::table('hourly_stats')->where('campaign_id', 1)->where('hour', '2026-10-15 10:00:00')->first();
        $this->assertSame([2, 1, 250], [(int) $row->impressions, (int) $row->clicks, (int) $row->spend]);
        $this->assertSame(3, DB::table('hourly_stats')->count());
    }

    public function test_second_flush_adds_to_existing_rows()
    {
        $this->svc()->flush([$this->ev(1, 'impression', '2026-10-15 10:05:00', 100)]);
        $this->svc()->flush([
            $this->ev(1, 'impression', '2026-10-15 10:20:00', 50),
            $this->ev(1, 'click', '2026-10-15 10:21:00', 0),
        ]);

        $row = DB::table('hourly_stats')->where('campaign_id', 1)->first();
        $this->assertSame([2, 1, 150], [(int) $row->impressions, (int) $row->clicks, (int) $row->spend]);
        $this->assertSame(1, DB::table('hourly_stats')->count());
    }

    public function test_whole_batch_is_one_statement()
    {
        $events = [];
        for ($i = 0; $i < 3000; $i++) {
            $events[] = $this->ev(1 + $i % 3, $i % 10 === 0 ? 'click' : 'impression', '2026-10-15 ' . sprintf('%02d', intdiv($i, 3) % 24) . ':00:00', 5);
        }

        DB::enableQueryLog();
        $n = $this->svc()->flush($events);
        $queries = array_filter(DB::getQueryLog(), function ($q) {
            return stripos($q['query'], 'hourly_stats') !== false;
        });

        $this->assertSame(72, $n);   // 3 кампании x 24 часа
        $this->assertCount(1, $queries, 'пакет должен уйти одним запросом, а не по строке');
        $this->assertSame(3000, (int) DB::table('hourly_stats')->sum(DB::raw('impressions + clicks')));
    }

    public function test_unknown_type_writes_nothing()
    {
        try {
            $this->svc()->flush([
                $this->ev(1, 'impression', '2026-10-15 10:05:00', 1),
                $this->ev(1, 'view', '2026-10-15 10:06:00', 1),
            ]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
        }

        $this->assertSame(0, DB::table('hourly_stats')->count());
    }

    public function test_empty_batch_is_a_noop()
    {
        DB::enableQueryLog();

        $this->assertSame(0, $this->svc()->flush([]));
        $this->assertCount(0, DB::getQueryLog());
    }
}
