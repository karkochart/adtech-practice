-- Задача 05: индекс есть, а запрос его не использует (условие: sql/TASKS.md)
-- Новых индексов не создавать. Файл должен запускаться повторно: строки удаления индексов в конце не трогать.

CREATE INDEX idx_created      ON events (created_at);
CREATE INDEX idx_click_id     ON events (click_id);
CREATE INDEX idx_camp_created ON events (campaign_id, created_at);

SET @now = (SELECT MAX(created_at) FROM events);

-- ============ A. События за 14 октября ============
SELECT COUNT(*) FROM events WHERE DATE(created_at) = '2026-10-14';
EXPLAIN SELECT COUNT(*) FROM events WHERE DATE(created_at) = '2026-10-14';
EXPLAIN ANALYZE SELECT COUNT(*) FROM events WHERE DATE(created_at) = '2026-10-14';
-- До:  143479 строк. type = index, key = idx_created, rows = 1 992 450, Extra = Using where; Using index, ≈ 320 мс.
--      Индекс читается ЦЕЛИКОМ (2 млн записей): DATE() считается для каждой, найти «день» в дереве нельзя.
--      Индекс выбран только потому, что он уже таблицы (покрывающий для COUNT), а не для поиска.

-- Исправленный вариант: полуоткрытый интервал по самой колонке.
SELECT COUNT(*) FROM events WHERE created_at >= '2026-10-14' AND created_at < '2026-10-15';
EXPLAIN SELECT COUNT(*) FROM events WHERE created_at >= '2026-10-14' AND created_at < '2026-10-15';
EXPLAIN ANALYZE SELECT COUNT(*) FROM events WHERE created_at >= '2026-10-14' AND created_at < '2026-10-15';
-- После: 143479 строк (совпало). type = range, key = idx_created, Extra = Using where; Using index, ≈ 35 мс (≈ 9x).
--        Оценка rows = 269 438, а реально прочитано 143 479 - EXPLAIN это оценка, факт - в EXPLAIN ANALYZE.
-- Ловушка: created_at = '2026-10-14' (или = DATE_FORMAT('2026-10-14', ...)) сравнивает с одним моментом 00:00:00
--          и возвращает 1. План при этом «идеальный» (ref, rows = 1) - сначала сверять результат, потом план.


-- ============ B. Клик по click_id ============
SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = 353957;
EXPLAIN SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = 353957;
EXPLAIN ANALYZE SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = 353957;
-- До:  1 строка. type = ALL, possible_keys = idx_click_id, key = NULL, rows = 1 992 450, ≈ 415 мс.
--      Индекс «возможен», но не используется. FORCE INDEX (idx_click_id) тоже не помогает - план тот же ALL.

-- Исправленный вариант: значение того же типа, что и колонка (VARCHAR) - строка.
SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = '353957';
EXPLAIN SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = '353957';
EXPLAIN ANALYZE SELECT id, campaign_id, user_id, created_at FROM events WHERE click_id = '353957';
-- После: та же строка (id = 353957, кампания 77). type = ref, key = idx_click_id, key_len = 147, rows = 1, ≈ 0,03 мс.
--        В приложении: передавать click_id в запрос строкой (в PHP - (string) перед биндингом).


-- ============ C. Последний час ============
SELECT COUNT(*), SUM(cost) FROM events WHERE created_at + INTERVAL 1 HOUR > @now;
EXPLAIN SELECT COUNT(*), SUM(cost) FROM events WHERE created_at + INTERVAL 1 HOUR > @now;
EXPLAIN ANALYZE SELECT COUNT(*), SUM(cost) FROM events WHERE created_at + INTERVAL 1 HOUR > @now;
-- До:  5866 / 1 619 720. type = ALL, possible_keys = NULL, rows = 1 992 450, ≈ 680 мс.
--      Арифметика над колонкой - та же функция над колонкой: possible_keys пуст, индекс даже не рассматривается.

-- Исправленный вариант: арифметику перенести на сторону константы.
SELECT COUNT(*), SUM(cost) FROM events WHERE created_at > @now - INTERVAL 1 HOUR;
EXPLAIN SELECT COUNT(*), SUM(cost) FROM events WHERE created_at > @now - INTERVAL 1 HOUR;
EXPLAIN ANALYZE SELECT COUNT(*), SUM(cost) FROM events WHERE created_at > @now - INTERVAL 1 HOUR;
-- После: 5866 / 1 619 720 (совпало). type = range, key = idx_created, rows = 5866,
--        Extra = Using index condition, ≈ 17 мс (≈ 40x). Не Using index: за cost приходится ходить в таблицу.


-- ============ D. Кампания 111, час 2026-10-15 09 ============
SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111 AND DATE_FORMAT(created_at, '%Y-%m-%d %H') = '2026-10-15 09'
GROUP BY type;
EXPLAIN SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111 AND DATE_FORMAT(created_at, '%Y-%m-%d %H') = '2026-10-15 09'
GROUP BY type;
EXPLAIN ANALYZE SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111 AND DATE_FORMAT(created_at, '%Y-%m-%d %H') = '2026-10-15 09'
GROUP BY type;
-- До:  impression 32, click 1. type = ref, key = idx_camp_created, key_len = 4, rows = 18 564,
--      Extra = Using index condition; Using temporary, ≈ 2,5 мс.
--      Индекс используется только на campaign_id (4 байта): читаются ВСЕ 18 564 события кампании,
--      DATE_FORMAT проверяется на каждом (index condition), подходят 33.

-- Исправленный вариант: полуоткрытый интервал на час, даты - литералами, без функций.
SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111
  AND created_at >= '2026-10-15 09:00:00'
  AND created_at <  '2026-10-15 10:00:00'
GROUP BY type;
EXPLAIN SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111
  AND created_at >= '2026-10-15 09:00:00'
  AND created_at <  '2026-10-15 10:00:00'
GROUP BY type;
EXPLAIN ANALYZE SELECT type, COUNT(*) FROM events
WHERE campaign_id = 111
  AND created_at >= '2026-10-15 09:00:00'
  AND created_at <  '2026-10-15 10:00:00'
GROUP BY type;
-- После: impression 32, click 1 (совпало). type = range, key = idx_camp_created, key_len = 9, rows = 33,
--        ≈ 0,1-0,6 мс (≈ 5-30x, при 18 тыс. строк разница ещё небольшая, у «жирной» кампании будет на порядки).
--        Using temporary остался - это GROUP BY type: type нет в индексе. Для 33 строк это не проблема.


-- ============ Вопросы (ответы комментариями) ============
-- 1. Функция над колонкой и над константой:
--    Индекс (B-дерево) упорядочен по значениям КОЛОНКИ. Для DATE(created_at) = X MySQL не знает, где в дереве
--    лежат «все значения, у которых DATE() = X», - приходится вычислить функцию для каждой записи.
--    Функция над константой (@now - INTERVAL 1 HOUR, DATE_FORMAT('2026-10-15 09', ...)) вычисляется ОДИН раз
--    до выполнения (<cache>(...) в плане), получается обычное значение, и по нему ищут в дереве.
--    Правило: колонка в WHERE должна стоять «голой», всё преобразование - на стороне значения.
--
-- 2. VARCHAR против числа, INT против строки:
--    VARCHAR = число: MySQL сравнивает КАК ЧИСЛА, т.е. превращает в число каждое значение колонки.
--    Числу 353957 равны '353957', '0353957', ' 353957', '353957abc' (проверено: все дают 1), а в строковом
--    порядке индекса они лежат в разных местах - поэтому поиск по индексу невозможен, даже с FORCE INDEX.
--    INT = '111': превращается одна константа '111' -> 111, колонка остаётся «голой» - индекс работает (ref).
--    Опасна конвертация колонки, а не константы.
--
-- 3. D: чем плох исходный запрос, что показывает key_len:
--    key_len - сколько байт индекса реально используется для поиска. idx_camp_created = campaign_id INT (4 байта)
--    + created_at DATETIME (5 байт). До: key_len = 4 - только campaign_id, все 18 564 события кампании читаются
--    и фильтруются. После: key_len = 9 - обе колонки, читаются ровно 33 нужные записи.
--    `key` не пустой ещё не значит, что индекс используется полностью. Для click_id key_len = 147:
--    VARCHAR(36) в utf8mb4 = 36 * 4 + 2 байта длины + 1 байт на NULL.
--
-- 4. Функциональный индекс INDEX ((DATE(created_at))):
--    Разумно, когда запрос поменять нельзя (чужой код, ORM, отчётный инструмент) или выражение - естественный
--    способ спрашивать (например LOWER(email) для поиска без учёта регистра).
--    Минусы: работает только для ТОЧНО того же выражения (DATE(created_at) - да, DATE_FORMAT(...) - нет);
--    ещё один индекс - медленнее вставка и больше места; для дат проще и универсальнее переписать на диапазон,
--    который заодно работает для любого периода (час, день, неделя) на одном обычном индексе.
--
-- 5. LIKE '3539%' и LIKE '%3957':
--    '3539%' - известное начало строки = диапазон в отсортированном индексе: type = range, rows = 2.
--    '%3957' - начало неизвестно, искать в дереве не с чего: type = index, 1 992 450 записей (весь индекс).
--    Если поиск по окончанию нужен часто - хранить перевёрнутую строку и искать LIKE '7593%', или полнотекст/поиск.
--
-- 6. Где ещё та же ловушка с типами:
--    JOIN по колонкам разных типов (VARCHAR click_id = INT id) - индекс на строковой стороне не работает.
--    JOIN по строкам разных кодировок или collation (utf8mb3 и utf8mb4, utf8mb4_unicode_ci и utf8mb4_0900_ai_ci) -
--    одну сторону приходится конвертировать, её индекс пропадает. В плане видно как convert(...) в условии.
--    В PHP: Laravel биндит int как PDO::PARAM_INT, и в MySQL уходит число - тот же эффект, что в B.
--
-- 7. Почему не BETWEEN ... '23:59:59':
--    BETWEEN включает обе границы. Если колонку сделают DATETIME(3) или TIMESTAMP(6), события в 23:59:59.500
--    молча пропадут. Если же взять BETWEEN '09:00:00' AND '10:00:00', событие ровно в 10:00:00 попадёт
--    в два соседних часа и посчитается дважды. Полуоткрытый интервал [начало, конец) не зависит от точности
--    колонки, соседние периоды стыкуются без дыр и без пересечений, а конец - это просто начало следующего периода.

ALTER TABLE events DROP INDEX idx_created, DROP INDEX idx_click_id, DROP INDEX idx_camp_created;
