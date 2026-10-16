<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PracticeSeedBig extends Command
{
    protected $signature = 'practice:seed-big {--events=2000000} {--force}';
    protected $description = 'ОЧИЩАЕТ events/campaigns и заливает датасет для SQL-тренировки (MySQL). Только dev.';

    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('Это TRUNCATE events, campaigns, charges, hourly_stats. Продолжить?')) {
            return 1;
        }
        $events = (int) $this->option('events');
        $t = function ($name, callable $fn) {
            $s = microtime(true);
            $fn();
            $this->line(sprintf('  %-34s %5.1fs', $name, microtime(true) - $s));
        };

        // индексы из заданий убираем, чтобы датасет всегда стартовал в "плохом" состоянии
        foreach (['idx_camp_type_created', 'idx_created', 'idx_click_id'] as $idx) {
            if (DB::select("SHOW INDEX FROM events WHERE Key_name = ?", [$idx])) {
                DB::statement("ALTER TABLE events DROP INDEX $idx");
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['events', 'charges', 'hourly_stats', 'campaigns'] as $table) {
            DB::statement("TRUNCATE TABLE $table");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $t('campaigns (200)', function () {
            DB::unprepared("INSERT INTO campaigns (name, daily_budget, spent_today, status, created_at, updated_at)
                SELECT CONCAT('campaign-', n), 1000000000000, 0, IF(n % 20 = 0, 'paused', 'active'), NOW(), NOW()
                FROM (SELECT a.n + b.n * 10 + c.n * 100 + 1 AS n FROM
                      (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                      (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
                      (SELECT 0 n UNION SELECT 1) c) x
                WHERE n <= 200");
        });

        // MySQL не позволяет ссылаться на TEMPORARY-таблицу дважды в одном запросе, поэтому обычная служебная.
        DB::unprepared('DROP TABLE IF EXISTS _digits');
        DB::unprepared('CREATE TABLE _digits (n TINYINT NOT NULL)');
        DB::unprepared('INSERT INTO _digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');

        $t("events ($events)", function () use ($events) {
            // 10^6 строк за проход; повторяем, пока не набрали нужное число
            $done = 0;
            while ($done < $events) {
                $take = min(1000000, $events - $done);
                DB::unprepared("INSERT INTO events (campaign_id, user_id, type, cost, created_at)
                    SELECT 1 + FLOOR(RAND() * 200), 1 + FLOOR(RAND() * 100000),
                           IF(RAND() < 0.03, 'click', 'impression'), FLOOR(50 + RAND() * 450),
                           NOW() - INTERVAL FLOOR(RAND() * 14 * 86400) SECOND
                    FROM _digits a, _digits b, _digits c, _digits d, _digits e, _digits f LIMIT $take");
                $done += $take;
            }
        });
        DB::unprepared('DROP TABLE _digits');

        // Новые активные кампании без единого события (для задач на LEFT JOIN и нули).
        $t('campaigns без событий (5)', function () {
            DB::unprepared("INSERT INTO campaigns (name, daily_budget, spent_today, status, created_at, updated_at)
                SELECT CONCAT('new-campaign-', n), 1000000000000, 0, 'active', NOW(), NOW()
                FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) x");
        });

        $t('click_id (числовые строки у кликов)', function () {
            DB::unprepared("UPDATE events SET click_id = CAST(id AS CHAR) WHERE type = 'click'");
        });
        $t('ANALYZE TABLE', function () {
            DB::select('ANALYZE TABLE events, campaigns');
        });

        foreach (['campaigns', 'events'] as $table) {
            $this->line(sprintf('  %-12s %s rows', $table, number_format(DB::table($table)->count())));
        }

        return 0;
    }
}
