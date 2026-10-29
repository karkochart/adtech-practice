<?php

namespace Tests\Feature;

use App\Services\MessagePublisher;
use App\Services\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingPublisher;
use Tests\TestCase;

/**
 * ЗАДАЧА 5, параллельные relay: несколько ПРОЦЕССОВ (pcntl_fork) с отдельными подключениями к MySQL.
 * Данные коммитятся (RefreshDatabase тут не подходит, см. BudgetConcurrencyTest) и удаляются в tearDown.
 *
 * Требование: сообщение, которое не падало и не терялось, уходит в брокер ровно один раз, сколько бы relay ни работало.
 */
class OutboxConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('нужны pcntl и posix');
        }
        $this->artisan('migrate', ['--force' => true]);
        DB::table('outbox')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('outbox')->delete();
        parent::tearDown();
    }

    /** @return string[][] доставленные ключи по процессам */
    private function runRelays($processes, $batchSize)
    {
        DB::disconnect();
        $children = [];
        for ($i = 0; $i < $processes; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'ob');
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    $publisher = new RecordingPublisher();
                    $publisher->delayMicros = 3000;
                    app()->instance(MessagePublisher::class, $publisher);
                    $relay = app(OutboxRelay::class);
                    while ($relay->publishPending($batchSize) > 0) {
                    }
                    $result = json_encode($publisher->keys());
                } catch (\Throwable $e) {
                    $result = json_encode(['error:' . get_class($e) . ':' . $e->getMessage()]);
                }
                file_put_contents($file, $result);
                posix_kill(posix_getpid(), SIGKILL);   // exit() запустил бы shutdown-обработчики PHPUnit
            }
            $children[] = [$pid, $file];
        }
        $delivered = [];
        foreach ($children as $child) {
            pcntl_waitpid($child[0], $status);
            $content = (string) file_get_contents($child[1]);
            $delivered[] = $content !== '' ? json_decode($content, true) : ['no-result'];
            @unlink($child[1]);
        }
        DB::reconnect();

        return $delivered;
    }

    public function test_parallel_relays_deliver_every_message_exactly_once()
    {
        for ($i = 1; $i <= 40; $i++) {
            DB::table('outbox')->insert([
                'message_key' => "conc-$i",
                'topic' => 'test.topic',
                'payload' => json_encode(['n' => $i]),
            ]);
        }

        $perProcess = $this->runRelays(4, 5);

        $all = [];
        foreach ($perProcess as $keys) {
            $all = array_merge($all, $keys);
        }
        $this->assertSame([], preg_grep('/^(error|no-result)/', $all), 'процесс завершился с ошибкой: ' . json_encode($perProcess));
        $this->assertCount(40, $all, 'всего доставок: ' . json_encode(array_map('count', $perProcess)));
        $this->assertCount(40, array_unique($all), 'одно и то же сообщение ушло дважды');
        $this->assertSame(0, DB::table('outbox')->whereNull('sent_at')->count());
    }
}
