-- Задача 01: активные кампании и активность за последний час (условие: sql/TASKS.md)
-- Время считаем от @now (последнее событие в данных), а не от NOW(): данные сгенерированы раньше,
-- и окно «последний час от сейчас» было бы пустым.
SET @now = (SELECT MAX(created_at) FROM events);

SELECT
    c.id AS campaign_id,                                        -- из campaigns: у кампаний без событий e.campaign_id = NULL
    c.name,
    COUNT(CASE WHEN e.type = 'impression' THEN 1 END) AS impressions,   -- без ELSE: иначе COUNT посчитает и нули
    COUNT(CASE WHEN e.type = 'click' THEN 1 END) AS clicks
FROM campaigns AS c
LEFT JOIN events AS e
       ON e.campaign_id = c.id
      AND e.created_at > @now - INTERVAL 60 MINUTE              -- условие на ПРАВУЮ таблицу - в ON (см. ниже)
WHERE c.status = 'active'                                       -- условие на ЛЕВУЮ таблицу - в WHERE
GROUP BY c.id, c.name
ORDER BY impressions, c.id
LIMIT 10;

/*
ПОЧЕМУ ПРОВЕРКА ВРЕМЕНИ В ON, А НЕ В WHERE

Порядок такой: сначала выполняется FROM ... LEFT JOIN ... ON, потом WHERE.

1. LEFT JOIN с условием в ON.
   Для каждой кампании ищутся события, подходящие под ON (та же кампания И за последний час).
   Если таких нет, LEFT JOIN всё равно оставляет кампанию - одной строкой, где все колонки events = NULL.
   Дальше COUNT(CASE ... THEN 1 END) по этой строке даёт 0. Кампания попадает в результат с нулями.

2. LEFT JOIN, а время в WHERE.
   ON соединяет кампанию со ВСЕМИ её событиями (за 14 дней). Кампания без событий даёт строку с NULL.
   Потом WHERE проверяет e.created_at > @now - INTERVAL 60 MINUTE.
   Для строки с NULL это сравнение NULL > дата, результат - NULL (не TRUE), и WHERE строку отбрасывает.
   Итог: кампании без событий за час исчезают, LEFT JOIN фактически становится обычным JOIN.

Проверено на этих данных (без LIMIT):
   JOIN, время в WHERE       -> 190 строк
   LEFT JOIN, время в WHERE  -> 190 строк (та же ошибка)
   LEFT JOIN, время в ON     -> 195 строк (правильно: 190 кампаний с событиями + 5 new-campaign без событий)

Правило: в LEFT JOIN условия на присоединяемую (правую) таблицу ставим в ON,
         условия на основную (левую) таблицу - в WHERE.
Исключение-приём: WHERE e.id IS NULL после LEFT JOIN - это осознанный поиск строк БЕЗ пары (anti-join).

ЕЩЁ ДВЕ ЛОВУШКИ ИЗ ЭТОЙ ЗАДАЧИ
- COUNT(выражение) считает значения, которые НЕ NULL. В CASE ... THEN 1 ELSE 0 END ноль - не NULL,
  поэтому считались все строки (показы = клики). Без ELSE получается NULL, и COUNT его пропускает.
- Альтернатива: COALESCE(SUM(e.type = 'impression'), 0). SUM по одним NULL даёт NULL, поэтому COALESCE обязателен.
*/
