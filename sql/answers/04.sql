-- Задача 04: почасовые клики кампании 111 за 48 полных часов, пустые часы с нулём (условие: sql/TASKS.md)
SET @now = (SELECT MAX(created_at) FROM events);
SET @to  = CAST(DATE_FORMAT(@now, '%Y-%m-%d %H:00:00') AS DATETIME);  -- начало текущего (неполного) часа

-- Основной запрос: окно - 48 ПОЛНЫХ часов до @to, сортировка по возрастанию, колонка hour.
-- Ловушка: старт с @now - 47 часов и остановка на часе самого @now сдвигает окно на час
-- и захватывает неполный текущий час (16:00, всего 10 минут данных), теряя 2026-10-13 16:00.
WITH RECURSIVE hours AS (
    SELECT @to - INTERVAL 48 HOUR AS hour
    UNION ALL
    SELECT hour + INTERVAL 1 HOUR
    FROM hours
    WHERE hour + INTERVAL 1 HOUR < @to          -- последний час окна: @to - 1 час
)
SELECT
    h.hour,
    COUNT(e.id) AS clicks                       -- COUNT по колонке правой таблицы: пустой час даёт 0
FROM hours h
LEFT JOIN events e
       ON e.created_at >= h.hour
      AND e.created_at <  h.hour + INTERVAL 1 HOUR
      AND e.campaign_id = 111                   -- условия на events - в ON (урок задачи 01)
      AND e.type = 'click'
GROUP BY h.hour
ORDER BY h.hour;

-- Бонус: самая длинная серия часов подряд без кликов (на базе основного запроса).
-- Приём «gaps and islands»: две нумерации - сквозная по всем часам и отдельная внутри группы
-- «ноль кликов / не ноль». Пока часы без кликов идут подряд, обе нумерации растут на 1 одновременно,
-- поэтому их разность постоянна - это и есть номер «острова». Любой час с кликами разрывает серию,
-- и у следующего острова разность другая.
--   час:            10 11 12 13 14 15
--   кликов:          0  2  0  0  0  1
--   сквозной №:      1  2  3  4  5  6
--   № среди нулей:   1  -  2  3  4  -
--   разность:        0  -  1  1  1  -     -> острова: {10} и {12,13,14}
WITH RECURSIVE hours AS (
    SELECT @to - INTERVAL 48 HOUR AS hour
    UNION ALL
    SELECT hour + INTERVAL 1 HOUR
    FROM hours
    WHERE hour + INTERVAL 1 HOUR < @to
),
hourly AS (
    SELECT h.hour, COUNT(e.id) AS clicks
    FROM hours h
    LEFT JOIN events e
           ON e.created_at >= h.hour
          AND e.created_at <  h.hour + INTERVAL 1 HOUR
          AND e.campaign_id = 111
          AND e.type = 'click'
    GROUP BY h.hour
),
islands AS (
    SELECT hour, clicks,
           ROW_NUMBER() OVER (ORDER BY hour)
         - ROW_NUMBER() OVER (PARTITION BY clicks = 0 ORDER BY hour) AS grp
    FROM hourly
)
SELECT MIN(hour) AS start_hour,
       MAX(hour) AS end_hour,
       COUNT(*)  AS hours_without_clicks
FROM islands
WHERE clicks = 0
GROUP BY grp
ORDER BY hours_without_clicks DESC, start_hour      -- при равной длине - более ранняя серия
LIMIT 1;

/*
Другой вариант бонуса (рекурсивный счётчик нулей подряд) - тоже рабочий.
Он даёт максимум 6 с концом в 2026-10-15 15:00 - совпадает с ответом выше.
Ограничения: нужен ещё финальный SELECT (строка с максимумом счётчика, start = end - (длина - 1) часов);
глубина рекурсии ограничена cte_max_recursion_depth (по умолчанию 1000): на ряде длиннее 1000 часов упадёт.
Ниже - как было (с прежним окном):

WITH RECURSIVE
    hours AS (
        SELECT CAST(
                   DATE_FORMAT(
                       @now - INTERVAL 47 HOUR,
                       '%Y-%m-%d %H:00:00'
                   ) AS DATETIME
               ) AS hour_start

        UNION ALL

        SELECT hour_start + INTERVAL 1 HOUR
FROM hours
WHERE hour_start < DATE_FORMAT(@now, '%Y-%m-%d %H:00:00')
    ),
    hourly_stats AS (
SELECT
    h.hour_start,
    COUNT(e.id) AS clicks
FROM hours h
    LEFT JOIN events e
ON e.created_at >= h.hour_start
    AND e.created_at < h.hour_start + INTERVAL 1 HOUR
    AND e.campaign_id = 111
    AND e.type = 'click'
GROUP BY h.hour_start
    ),
    numbered AS (
SELECT
    hour_start,
    clicks,
    ROW_NUMBER() OVER (ORDER BY hour_start) AS rn
FROM hourly_stats
    ),
    counter AS (
SELECT
    hour_start,
    clicks,
    rn,
    CASE WHEN clicks = 0 THEN 1 ELSE 0 END AS no_clicks
FROM numbered
WHERE rn = 1

UNION ALL

SELECT
    n.hour_start,
    n.clicks,
    n.rn,
    CASE
    WHEN n.clicks = 0 THEN c.no_clicks + 1
    ELSE 0
    END
FROM numbered n
    JOIN counter c ON n.rn = c.rn + 1
    )
SELECT
    hour_start,
    clicks,
    no_clicks
FROM counter
ORDER BY hour_start DESC;
*/

-- Ответы на вопросы:
-- 1) Рекурсивный CTE без условия остановки и cte_max_recursion_depth:
--    Без условия в рекурсивной части (здесь - WHERE hour + INTERVAL 1 HOUR < @to) каждая итерация
--    порождает следующую бесконечно, расходуя CPU/память, пока сервер не упрётся в лимит
--    cte_max_recursion_depth (по умолчанию 1000 итераций) и не оборвёт запрос ошибкой
--    3636 "Recursive query aborted after N iterations" - это аварийный тормоз, а не механизм
--    остановки, на него полагаться нельзя: условие выхода всегда пишем в самом запросе.
-- 2) Почему ряд часов не получить одним GROUP BY по events:
--    GROUP BY группирует только те значения, которые реально есть в строках таблицы. Если за час
--    не было ни одного события, строки для этого часа просто не существует - группировать нечего,
--    и час выпадает из результата (29 часов из 48, а не 48). Чтобы получить все 48, нужен независимый
--    от events источник полного ряда часов (здесь - WITH RECURSIVE), к которому events
--    присоединяются через LEFT JOIN - тогда пустой час остаётся строкой с COUNT = 0.
