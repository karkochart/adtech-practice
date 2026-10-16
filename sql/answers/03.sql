-- Задача 03: подобрать индекс под запрос (условие: sql/TASKS.md)
-- Файл должен запускаться повторно: в конце удалите свой индекс.

-- Шаг 1. План ДО индекса
EXPLAIN
SELECT id, campaign_id, created_at
FROM events
WHERE user_id = 65073 AND type = 'impression'
ORDER BY created_at DESC
LIMIT 20;

EXPLAIN ANALYZE
SELECT id, campaign_id, created_at
FROM events
WHERE user_id = 65073 AND type = 'impression'
ORDER BY created_at DESC
LIMIT 20;
-- До:  type = ALL   rows = 1 992 450   Extra = Using where; Using filesort   время ≈ 443 мс
--       (полный просмотр 2 млн строк, потом сортировка; Handler_read_rnd_next = 2 000 001)

-- Шаг 2. Ваш индекс (ALTER TABLE events ADD INDEX ...):

CREATE INDEX idx_events_user_id_type_created_at_campaign_id
    ON events (user_id, type, created_at, campaign_id);

-- Шаг 3. План ПОСЛЕ индекса (скопируйте EXPLAIN и EXPLAIN ANALYZE из шага 1):
EXPLAIN
SELECT id, campaign_id, created_at
FROM events
WHERE user_id = 65073 AND type = 'impression'
ORDER BY created_at DESC
    LIMIT 20;

EXPLAIN ANALYZE
SELECT id, campaign_id, created_at
FROM events
WHERE user_id = 65073 AND type = 'impression'
ORDER BY created_at DESC
    LIMIT 20;
-- После: type = ref   rows = 49   Extra = Backward index scan; Using index   время ≈ 0,02 мс
--        (Covering index lookup: прочитано 20 записей индекса, в таблицу не ходим, сортировки нет)
--        Промежуточный вариант (user_id, type): ref, 49 строк, но Using filesort и 0,2 мс - сортировал отдельно.

-- Шаг 4 (бонус). Покрывающий индекс: Extra = Using index
-- Сделан сразу в шаге 2: индекс (user_id, type, created_at, campaign_id) уже покрывающий.
-- В нём есть все колонки запроса: user_id и type (WHERE), created_at (ORDER BY и SELECT),
-- campaign_id (SELECT) и неявно id (первичный ключ). Поэтому Extra = Using index.


-- Шаг 5. Удаление индекса (ALTER TABLE events DROP INDEX ...):

drop index idx_events_user_id_type_created_at_campaign_id
    on events;

-- Ответы на вопросы:
-- 1) Порядок колонок, и что с (created_at, user_id, type):
--    Сначала колонки с условием равенства (user_id, type) - по ним индекс сразу сужается до 49 записей.
--    Затем колонка сортировки (created_at): внутри одной пары (user_id, type) записи уже лежат по времени,
--    поэтому сортировать не нужно. В конце - колонки, которые только читаются (campaign_id) - для покрытия.
--    Правило: равенства -> диапазон или сортировка -> «просто прочитать».
--    С (created_at, user_id, type) поиск по user_id невозможен: индекс упорядочен по времени, а нужный
--    пользователь разбросан по всему индексу. MySQL идёт по индексу с конца (type = index, Backward index scan)
--    и проверяет каждую запись, пока не наберёт 20. Проверено: EXPLAIN показывает rows = 20 (оценка!),
--    а реально прочитано 691 227 записей (Handler_read_prev). Против 20 с правильным индексом.
-- 2) Почему id «покрыт» без явного добавления:
--    В InnoDB таблица хранится в кластерном индексе по первичному ключу, а каждая запись вторичного
--    индекса содержит значение первичного ключа - по нему MySQL при необходимости находит строку.
--    Значит, id уже есть в любом вторичном индексе. Следствие: длинный или случайный первичный ключ
--    раздувает все вторичные индексы.
-- 3) Почему DESC не требует сортировки:
--    Индекс - упорядоченная структура (B-дерево), его можно читать в обе стороны. Для DESC MySQL читает
--    записи с конца диапазона (в плане - Backward index scan, в EXPLAIN ANALYZE - reverse).
--    В MySQL 8 можно объявить и колонку индекса как DESC - это нужно для смешанного порядка,
--    например ORDER BY a ASC, b DESC.
-- 4) Чем плохи индексы «на всякий случай»:
--    - каждый INSERT, UPDATE и DELETE обновляет все индексы - запись медленнее, особенно на нагрузке с событиями;
--    - индексы занимают место на диске и в буферном пуле, вытесняя полезные данные из памяти;
--    - индексы на отдельные колонки часто бесполезны: MySQL обычно берёт один индекс на таблицу,
--      а составной индекс под конкретный запрос работает лучше набора одиночных;
--    - лишние индексы увеличивают время ALTER TABLE и усложняют работу оптимизатора.
--    Индекс добавляют под конкретный медленный запрос, проверяют EXPLAIN, а неиспользуемые удаляют
--    (sys.schema_unused_indexes).

-- Дополнительно: стоит ли добавлять campaign_id ради покрытия (замерено на этих данных)
--    Размер: (user_id, type, created_at) 82,7 МБ -> с campaign_id 91,8 МБ (+9 МБ: INT 4 байта x 2 млн строк).
--    Этот запрос (20 строк): ≈ 0,075 мс -> ≈ 0,02 мс. В разы, но это сотые доли миллисекунды.
--    Запрос «клики по кампаниям за сутки» (143 тыс. строк через индекс):
--      (created_at) ≈ 300 мс -> (created_at, campaign_id) ≈ 51 мс: исчезли 143 тыс. походов в таблицу.
--    Выгодно, когда: через индекс читается много строк; добавляемые колонки маленькие и не меняются
--      (events только пополняется); запрос частый и его SELECT стабилен; данные не помещаются в память.
--    Не стоит: запрос на единицы строк и редкий; SELECT * или широкие колонки (VARCHAR, TEXT, JSON);
--      колонка часто обновляется; таблица с тяжёлой записью и множеством индексов.
--    В MySQL нет INCLUDE (как в PostgreSQL), поэтому «читаемые» колонки ставят в конец индекса.
--    Итог здесь: для ленты хватает (user_id, type, created_at); campaign_id - дёшево, но не обязательно.
