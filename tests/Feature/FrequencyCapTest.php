<?php

namespace Tests\Feature;

use App\Services\FrequencyCap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ЗАДАЧА 3 (частотное ограничение). Реализовать App\Services\FrequencyCap::allow($userId, $campaignId): bool
 * (new FrequencyCap($max, $windowSeconds)).
 *  - пользователю показываем кампанию не больше $max раз за окно; окно считается от ПЕРВОГО показа
 *  - отказ не продлевает окно и не "съедает" лимит
 *  - пользователи и кампании независимы
 *  - счётчик должен быть атомарным (подсказка: Cache::add + Cache::increment, а не get/put)
 */
class FrequencyCapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_allows_up_to_max_then_denies()
    {
        $cap = new FrequencyCap(3, 3600);

        $this->assertTrue($cap->allow(1, 10));
        $this->assertTrue($cap->allow(1, 10));
        $this->assertTrue($cap->allow(1, 10));
        $this->assertFalse($cap->allow(1, 10));
        $this->assertFalse($cap->allow(1, 10));
    }

    public function test_users_and_campaigns_are_independent()
    {
        $cap = new FrequencyCap(1, 3600);

        $this->assertTrue($cap->allow(1, 10));
        $this->assertFalse($cap->allow(1, 10));
        $this->assertTrue($cap->allow(2, 10));   // другой пользователь
        $this->assertTrue($cap->allow(1, 11));   // другая кампания
    }

    public function test_window_expires_from_first_impression()
    {
        $cap = new FrequencyCap(2, 60);
        $this->assertTrue($cap->allow(1, 10));          // 12:00:00 - начало окна
        Carbon::setTestNow('2026-10-15 12:00:40');
        $this->assertTrue($cap->allow(1, 10));
        $this->assertFalse($cap->allow(1, 10));         // отказ в 12:00:40 не должен продлить окно

        Carbon::setTestNow('2026-10-15 12:01:01');      // окно от 12:00:00 истекло
        $this->assertTrue($cap->allow(1, 10));
    }
}
