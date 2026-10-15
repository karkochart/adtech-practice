<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Настоящий параллелизм: несколько ПРОЦЕССОВ (pcntl_fork) с отдельными подключениями к MySQL.
 *
 * Почему не RefreshDatabase: тот оборачивает тест в одну транзакцию на одном подключении; дети-процессы
 * не видят незакоммиченных данных родителя, а PHPUnit гоняет тесты строго по очереди. Поэтому тут данные
 * коммитятся и сами удаляются в tearDown.
 */
class BudgetConcurrencyTest extends TestCase
{
    private $campaignIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('нужны pcntl и posix');
        }
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::table('charges')->whereIn('campaign_id', $this->campaignIds)->delete();
        DB::table('campaigns')->whereIn('id', $this->campaignIds)->delete();
        parent::tearDown();
    }

    private function campaign($budget)
    {
        $c = Campaign::create(['name' => 'c', 'daily_budget' => $budget, 'spent_today' => 0, 'status' => 'active']);

        return $this->campaignIds[] = $c->id;
    }

    /** @return string[] ok | budget | conflict | domain | ИмяКласса */
    private function parallel(array $calls)
    {
        DB::disconnect();
        $children = [];
        foreach ($calls as $i => $call) {
            $file = tempnam(sys_get_temp_dir(), 'bc');
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    app(BudgetService::class)->charge($call[0], $call[1], $call[2]);
                    $result = 'ok';
                } catch (\App\Exceptions\BudgetExceededException $e) {
                    $result = 'budget';
                } catch (\App\Exceptions\ImpressionConflictException $e) {
                    $result = 'conflict';
                } catch (\DomainException $e) {
                    $result = 'domain';
                } catch (\Throwable $e) {
                    $result = get_class($e);
                }
                file_put_contents($file, $result);
                posix_kill(posix_getpid(), SIGKILL);   // exit() запустил бы shutdown-обработчики PHPUnit
            }
            $children[$i] = [$pid, $file];
        }
        $results = [];
        foreach ($children as $i => $child) {
            pcntl_waitpid($child[0], $status);
            $content = (string) file_get_contents($child[1]);
            $results[$i] = $content !== '' ? $content : 'no-result';
            @unlink($child[1]);
        }
        DB::reconnect();

        return $results;
    }

    public function test_parallel_charges_never_exceed_budget()
    {
        $c = $this->campaign(1000);
        $calls = [];
        for ($i = 0; $i < 20; $i++) {
            $calls[] = [$c, 'budget-' . uniqid() . "-$i", 100];
        }

        $results = $this->parallel($calls);

        $counts = array_count_values($results);
        $this->assertSame(10, isset($counts['ok']) ? $counts['ok'] : 0, 'ровно 10 списаний по 100 помещаются в бюджет 1000: ' . json_encode($counts));
        $this->assertSame(10, isset($counts['budget']) ? $counts['budget'] : 0);
        $this->assertSame(1000, (int) Campaign::find($c)->spent_today);
        $this->assertSame(10, DB::table('charges')->where('campaign_id', $c)->count());
    }

    public function test_parallel_duplicates_of_one_impression_charge_once()
    {
        $c = $this->campaign(1000);
        $imp = 'dup-' . uniqid();

        $results = $this->parallel(array_fill(0, 8, [$c, $imp, 100]));

        $this->assertSame(array_fill(0, 8, 'ok'), $results);
        $this->assertSame(100, (int) Campaign::find($c)->spent_today);
        $this->assertSame(1, DB::table('charges')->where('impression_id', $imp)->count());
    }

    /** Тот же impression_id параллельно на разные кампании: общих блокировок нет, спасает только UNIQUE. */
    public function test_parallel_same_impression_on_different_campaigns_gives_conflict_not_db_error()
    {
        $a = $this->campaign(1000);
        $b = $this->campaign(1000);

        for ($i = 0; $i < 10; $i++) {
            $imp = 'cross-' . uniqid() . "-$i";
            $results = $this->parallel([[$a, $imp, 100], [$b, $imp, 100]]);

            sort($results);
            $this->assertSame(['conflict', 'ok'], $results, "итерация $i: проигравший должен получить ImpressionConflictException");
        }
    }
}
