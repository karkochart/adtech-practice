-- Эталонные решения к sql/drills.md (MySQL 8.0, InnoDB). Не подглядывайте, пока не попробовали сами.
-- Запуск: make sql-solutions     (файл примонтирован в контейнер БД как /sql/solutions.sql)

SELECT '=== 1. Топ-10 кампаний по CTR за 7 дней (минимум 1000 показов)' AS '';
SELECT campaign_id,
       SUM(type = 'impression') AS impressions,
       SUM(type = 'click')      AS clicks,
       ROUND(100 * SUM(type = 'click') / NULLIF(SUM(type = 'impression'), 0), 2) AS ctr_pct
FROM events
WHERE created_at >= NOW() - INTERVAL 7 DAY
GROUP BY campaign_id
HAVING impressions >= 1000
ORDER BY ctr_pct DESC
LIMIT 10;

SELECT '=== 2. Последние 3 события каждой из кампаний 1..3 (ROW_NUMBER)' AS '';
SELECT campaign_id, id, type, created_at FROM (
    SELECT e.*, ROW_NUMBER() OVER (PARTITION BY campaign_id ORDER BY created_at DESC, id DESC) AS rn
    FROM events e WHERE campaign_id IN (1, 2, 3)
) x WHERE rn <= 3 ORDER BY campaign_id, rn;

SELECT '=== 3. Расход по дням и нарастающий итог (кампании 1, 2)' AS '';
SELECT campaign_id, d, day_spend,
       SUM(day_spend) OVER (PARTITION BY campaign_id ORDER BY d) AS running_spend
FROM (SELECT campaign_id, DATE(created_at) AS d, SUM(cost) AS day_spend
      FROM events WHERE campaign_id IN (1, 2) GROUP BY campaign_id, DATE(created_at)) x
ORDER BY campaign_id, d
LIMIT 8;

SELECT '=== 4. Медленный запрос: ДО индекса' AS '';
EXPLAIN SELECT COUNT(*) FROM events WHERE campaign_id = 42 AND type = 'click' AND created_at >= NOW() - INTERVAL 3 DAY;
ALTER TABLE events ADD INDEX idx_camp_type_created (campaign_id, type, created_at);
ANALYZE TABLE events;
SELECT '=== 4. ПОСЛЕ индекса (campaign_id, type, created_at): равенства, затем диапазон' AS '';
EXPLAIN SELECT COUNT(*) FROM events WHERE campaign_id = 42 AND type = 'click' AND created_at >= NOW() - INTERVAL 3 DAY;

SELECT '=== 5. Функция над колонкой отключает индекс' AS '';
ALTER TABLE events ADD INDEX idx_created (created_at);
EXPLAIN SELECT COUNT(*) FROM events WHERE DATE(created_at) = CURDATE();
SELECT '=== 5. Переписано в диапазон' AS '';
EXPLAIN SELECT COUNT(*) FROM events WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY;

SELECT '=== 6. Неявное приведение типов: click_id (VARCHAR) сравниваем с ЧИСЛОМ' AS '';
ALTER TABLE events ADD INDEX idx_click_id (click_id);
EXPLAIN SELECT id FROM events WHERE click_id = 1000;
SELECT '=== 6. Со строкой индекс работает' AS '';
EXPLAIN SELECT id FROM events WHERE click_id = '1000';

SELECT '=== 7. Keyset вместо OFFSET: страница после id = 1500000, кампания 42' AS '';
SELECT id, type, created_at FROM events
WHERE campaign_id = 42 AND id < 1500000
ORDER BY id DESC LIMIT 5;

SELECT '=== 8. Идемпотентный upsert агрегата (повтор прибавляет, а не заменяет)' AS '';
START TRANSACTION;
INSERT INTO hourly_stats (campaign_id, hour, impressions, clicks, spend)
VALUES (1, '2026-10-15 10:00:00', 5, 1, 300) AS new
ON DUPLICATE KEY UPDATE impressions = hourly_stats.impressions + new.impressions,
                        clicks      = hourly_stats.clicks + new.clicks,
                        spend       = hourly_stats.spend + new.spend;
INSERT INTO hourly_stats (campaign_id, hour, impressions, clicks, spend)
VALUES (1, '2026-10-15 10:00:00', 2, 0, 100) AS new
ON DUPLICATE KEY UPDATE impressions = hourly_stats.impressions + new.impressions,
                        clicks      = hourly_stats.clicks + new.clicks,
                        spend       = hourly_stats.spend + new.spend;
SELECT * FROM hourly_stats WHERE campaign_id = 1;     -- ожидаем 7, 1, 400
ROLLBACK;

SELECT '=== 9. EXPLAIN ANALYZE (фактическое время и строки, MySQL 8.0.18+)' AS '';
EXPLAIN ANALYZE SELECT COUNT(*) FROM events WHERE campaign_id = 42 AND type = 'click' AND created_at >= NOW() - INTERVAL 3 DAY;

SELECT '=== Чистка: вернуть БД в "плохое" состояние для повторной тренировки' AS '';
ALTER TABLE events DROP INDEX idx_camp_type_created, DROP INDEX idx_created, DROP INDEX idx_click_id;
