-- Задача 06: постраничный вывод, OFFSET против keyset (условие: sql/TASKS.md)
-- Нужны индексы idx_created (created_at) и idx_camp_created (campaign_id, created_at).
-- Если их нет, файл создаёт их сам и в конце удаляет; если они уже были (после задачи 05) - не трогает.

SET @had_created = (SELECT COUNT(*) FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = 'events' AND index_name = 'idx_created');
SET @had_camp    = (SELECT COUNT(*) FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = 'events' AND index_name = 'idx_camp_created');
SET @sql = IF(@had_created = 0, 'CREATE INDEX idx_created ON events (created_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(@had_camp = 0, 'CREATE INDEX idx_camp_created ON events (campaign_id, created_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SHOW INDEX FROM events;

-- ============ 1. OFFSET: страница 501 кампании 70 ============
EXPLAIN SELECT * FROM events WHERE campaign_id = 70 ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 10000;
EXPLAIN ANALYZE SELECT * FROM events WHERE campaign_id = 70 ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 10000;
-- type = ref, key = idx_camp_created, key_len = 4, Extra = Backward index scan (сортировки нет: индекс уже упорядочен).
-- Но прочитано 10 020 строк: 10 000 выброшено, 20 отданы. ≈ 20-40 мс (зависит от прогрева кэша). Время растёт вместе с номером страницы.

-- ============ 1б. OFFSET без фильтра по кампании: страница 50 001 ============
EXPLAIN SELECT * FROM events ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 1000000;
EXPLAIN ANALYZE SELECT * FROM events ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 1000000;
-- type = ALL, key = NULL, rows ≈ 2 млн, Extra = Using filesort. Таблица читается целиком (2 млн), сортируются 1 000 020
-- строк, 1 000 000 выбрасывается. ≈ 1,2 с на одну страницу. Индекс idx_created не выбран: чтение 1 млн записей индекса
-- с походами в таблицу за SELECT * оптимизатор оценил дороже полного скана.

-- ============ 2. Keyset: первая страница и следующая по курсору ============
-- Первая страница: курсора ещё нет.
SELECT id, created_at FROM events WHERE campaign_id = 70 ORDER BY created_at DESC, id DESC LIMIT 20;

-- Курсор - последняя строка страницы 500 (в приложении его отдаёт клиенту предыдущая страница).
SELECT created_at, id INTO @c_at, @c_id
FROM events WHERE campaign_id = 70 ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 9999;
SELECT @c_at AS c_at, @c_id AS c_id;   -- 2026-10-02 03:05:49, 1125608

-- Следующая страница: строго «после» курсора в порядке (created_at DESC, id DESC).
SELECT id, created_at FROM events
WHERE campaign_id = 70
  AND (created_at < @c_at OR (created_at = @c_at AND id < @c_id))
ORDER BY created_at DESC, id DESC
LIMIT 20;

-- ============ 3. Сверка страницы 501: keyset против OFFSET ============
-- Сравнение по позициям: на каждой позиции страницы должен стоять один и тот же id.
SELECT COUNT(*) AS n, SUM(o.id = k.id) AS same_positions, SUM(o.id) AS sum_id
FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC) AS rn
      FROM (SELECT id, created_at FROM events WHERE campaign_id = 70
            ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 10000) t) o
JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC) AS rn
      FROM (SELECT id, created_at FROM events
            WHERE campaign_id = 70 AND (created_at < @c_at OR (created_at = @c_at AND id < @c_id))
            ORDER BY created_at DESC, id DESC LIMIT 20) t) k ON k.rn = o.rn;
-- Результат: n = 20, same_positions = 20, sum_id = 22715981 - страница совпала до строки.

-- ============ 4. Неверный курсор только по created_at, граница страниц 509 / 510 ============
-- Курсор - последняя строка страницы 509. У следующей строки (id 919378) то же created_at.
SET @c_at = '2026-10-01 20:27:14';
SET @c_id = 1995008;

-- Верно:
SELECT id, created_at FROM events
WHERE campaign_id = 70 AND (created_at < @c_at OR (created_at = @c_at AND id < @c_id))
ORDER BY created_at DESC, id DESC LIMIT 3;
-- Первая строка: id 919378 - она и есть начало страницы 510.

-- Неверно: курсор только по времени.
SELECT id, created_at FROM events
WHERE campaign_id = 70 AND created_at < @c_at
ORDER BY created_at DESC, id DESC LIMIT 3;
-- Первая строка: id 1447469. Событие 919378 пропало: у него created_at = '2026-10-01 20:27:14', то есть не меньше курсора,
-- а «<» отбрасывает все строки с тем же временем, включая те, что не уместились на предыдущую страницу.
-- Замена на «<=» даст обратную ошибку: строка с id 1995008 (сам курсор) появится на следующей странице второй раз.

-- ============ 5. EXPLAIN ANALYZE keyset-запросов ============
SELECT created_at, id INTO @c_at, @c_id
FROM events WHERE campaign_id = 70 ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 9999;

-- 5а. Условие через OR (используется):
EXPLAIN SELECT * FROM events
WHERE campaign_id = 70 AND (created_at < @c_at OR (created_at = @c_at AND id < @c_id))
ORDER BY created_at DESC, id DESC LIMIT 20;
EXPLAIN ANALYZE SELECT * FROM events
WHERE campaign_id = 70 AND (created_at < @c_at OR (created_at = @c_at AND id < @c_id))
ORDER BY created_at DESC, id DESC LIMIT 20;
-- type = range, key = idx_camp_created, key_len = 17 (campaign_id 4 + created_at 5 + id 8), Backward index scan,
-- Using index condition. Читается ровно 20 строк, ≈ 0,1 мс (против ≈ 20-40 мс у OFFSET 10000).
-- Время не зависит от глубины страницы: поиск стартует прямо с курсора.

-- 5б. Тот же курсор записью со сравнением строк (a, b) < (x, y) - выглядит аккуратнее, но индекс не использует:
EXPLAIN ANALYZE SELECT id FROM events
WHERE campaign_id = 70 AND (created_at, id) < (@c_at, @c_id)
ORDER BY created_at DESC, id DESC LIMIT 20;
-- key_len = 4: из индекса используется только campaign_id, а условие курсора применяется фильтром.
-- Читается 10 020 строк (≈ 4 мс) - как у OFFSET, потому что просмотр начинается с конца кампании, а не с курсора.
-- В MySQL 8.0 сравнение строк «<» и «>» не превращается в диапазон по индексу (только «=» и IN), поэтому - вариант 5а.

-- 5в. Без фильтра по кампании, курсор = строка 1 000 000 (аналог OFFSET 1000000 из 1б):
SELECT created_at, id INTO @c_at, @c_id FROM events ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET 999999;
EXPLAIN ANALYZE SELECT * FROM events
WHERE created_at < @c_at OR (created_at = @c_at AND id < @c_id)
ORDER BY created_at DESC, id DESC LIMIT 20;
-- type = range, key = idx_created, Backward index scan, без Using filesort. ≈ 1,5 мс против ≈ 1,2 с у OFFSET.
-- Тот же запрос через (created_at, id) < (...) здесь в пробном запуске сканировал 1,9 млн строк и работал ≈ 5 с.

-- ============ Вопросы (ответы комментариями) ============
-- 1. Большой OFFSET:
--    MySQL не умеет «перепрыгнуть» N строк: он читает их по порядку (по индексу или после сортировки всей таблицы),
--    отдаёт в Limit и выбрасывает. Цена страницы пропорциональна номеру страницы: 10 020 прочитанных строк в 1,
--    миллион отсортированных в 1б. А если индекса под ORDER BY нет, ещё и filesort по всему набору.
--
-- 2. Сортировка только по created_at:
--    created_at не уникален (у кампании 70 54 момента времени встречаются дважды). Курсор «created_at < X» отбрасывает
--    все строки с временем X, в том числе те, что не поместились на предыдущую страницу, - строка 919378 пропала.
--    Нужен уникальный тайбрейкер: (created_at, id) однозначно задаёт позицию каждой строки, поэтому курсор
--    «после (X, id)» не теряет и не дублирует ничего. Порядок сортировки и сравнение курсора должны совпадать:
--    ORDER BY created_at DESC, id DESC - курсор «меньше» по обеим колонкам.
--
-- 3. Какой индекс нужен:
--    (campaign_id, created_at) - достаточно. InnoDB дописывает первичный ключ в конец каждого вторичного индекса,
--    поэтому фактически это (campaign_id, created_at, id): key_len = 17 = 4 + 5 + 8 это подтверждает.
--    Равенство по campaign_id, затем сортировка по двум следующим колонкам - ровно порядок индекса, filesort не нужен,
--    DESC читается обратным просмотром (Backward index scan). Если смешать направления (created_at DESC, id ASC),
--    обратным просмотром порядок уже не получить - потребуется filesort или индекс с явным DESC (MySQL 8.0).
--
-- 4. Что теряется по сравнению с OFFSET:
--    Нельзя прыгнуть на страницу N (нужен курсор предыдущей) и нет общего числа страниц (COUNT(*) по кампании сам дорог).
--    Как жить: интерфейс «вперёд / назад» или «загрузить ещё» вместо номеров страниц; предыдущую страницу получают тем же
--    запросом в обратном порядке (ASC и условие «больше»), затем разворачивают результат; общее число - приблизительно,
--    из счётчика или кэша; прыжок на дату - курсор строится из даты (created_at < '2026-10-01').
--
-- 5. Новые события между запросами:
--    OFFSET: новые строки попадают в начало списка, все остальные сдвигаются вниз на их число, и на следующей странице
--    читатель увидит строки, которые уже видел (или пропустит, если строки удалялись).
--    Keyset: курсор привязан к конкретной строке, а не к номеру позиции, поэтому добавления сверху ничего не сдвигают:
--    дублей и пропусков среди уже пройденного нет, новое видно только после возврата на первую страницу.
--    Исключение: событие, которое пришло позже, но с более ранним created_at (опоздавшие данные), в уже пройденную
--    часть не попадёт.
--
-- 6. Курсор и клиент:
--    Курсор отдают клиенту непрозрачной строкой (например base64 от «created_at|id»), а при разборе проверяют формат:
--    created_at - строго Y-m-d H:i:s, id - целое. Значения идут в запрос только через биндинги, не конкатенацией.
--    Курсор определяет лишь позицию в списке: условие по campaign_id (и права доступа к кампании) берётся
--    на сервере из сессии, а не из курсора, иначе клиент подменит кампанию. Если важно, чтобы курсор нельзя было подделать
--    или подобрать, его подписывают (HMAC).

SET @sql = IF(@had_camp = 0, 'ALTER TABLE events DROP INDEX idx_camp_created', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(@had_created = 0, 'ALTER TABLE events DROP INDEX idx_created', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
