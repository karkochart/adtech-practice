# SQL-тренировка (MySQL 8.0, InnoDB, 2 млн событий)

Подготовка: `make seed` (≈30 секунд), консоль: `make mysql`.
Таблицы: `events` (id, campaign_id, user_id, type = impression|click, cost, click_id, created_at) - **без вторичных индексов**; `campaigns`, `charges`, `hourly_stats`.
Решения: `sql/solutions.sql`, запуск `make sql-solutions` (всё прогнано на этих данных). Решайте сами, по 5-10 минут на задание.

| # | Задание | Что проверяют |
|---|---------|---------------|
| 1 | **Топ-10 кампаний по CTR** за 7 дней (клики / показы), только кампании с ≥ 1000 показов. | условная агрегация `SUM(type='click')`, `HAVING`, `NULLIF` против деления на 0 |
| 2 | **Последние 3 события** каждой из кампаний 1..3. | `ROW_NUMBER() OVER (PARTITION BY ...)` |
| 3 | **Расход по дням** и нарастающий итог по кампаниям 1 и 2. | подзапрос + `SUM() OVER (ORDER BY ...)` |
| 4 | **Медленный запрос:** `COUNT(*) WHERE campaign_id = 42 AND type = 'click' AND created_at >= NOW() - INTERVAL 3 DAY`. `EXPLAIN` до (`type: ALL`, 2 млн строк), подобрать индекс, сравнить. Почему порядок `(campaign_id, type, created_at)`, а не `(created_at, campaign_id, type)`? Что значит `Using index`? | составной индекс: равенства, потом диапазон; покрывающий индекс |
| 5 | **Функция над колонкой:** `WHERE DATE(created_at) = CURDATE()`. Почему индекс по `created_at` не работает как диапазон, как переписать? | sargable-условия |
| 6 | **Неявное приведение типов:** `WHERE click_id = 1000` против `'1000'` (колонка VARCHAR). Сравнить планы. | подводный камень с кастами |
| 7 | **Пагинация** истории кампании: чем плох `OFFSET 100000`, переписать на keyset. | `WHERE id < :last ORDER BY id DESC LIMIT n` |
| 8 | **Идемпотентный upsert агрегата:** `INSERT ... ON DUPLICATE KEY UPDATE`, повтор должен прибавлять. Чем `INSERT IGNORE` опасен (глотает и другие ошибки)? | `ON DUPLICATE KEY UPDATE`, алиас строки `AS new` |
| 9 | **`EXPLAIN ANALYZE`:** прочитать план запроса №4 (фактическое время, строки, loops). | чтение плана |

## Транзакции и блокировки руками (два терминала `make mysql`)

**A. Уровень по умолчанию.** `SELECT @@transaction_isolation;` - в MySQL это `REPEATABLE-READ` (в PostgreSQL по умолчанию READ COMMITTED).

**B. Снимок.** Сессия 1: `START TRANSACTION; SELECT spent_today FROM campaigns WHERE id=1;`. Сессия 2: `UPDATE campaigns SET spent_today = spent_today + 10 WHERE id=1;` (autocommit). Сессия 1: повторить `SELECT` - **та же цифра** (снимок). Затем сессия 1: `UPDATE campaigns SET spent_today = spent_today + 1 WHERE id=1; SELECT spent_today ...` - увидите чужое изменение: запись всегда идёт по свежим данным. Повторите с `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;` перед стартом.

**C. Lost update.** В обеих сессиях: `START TRANSACTION; SELECT spent_today FROM campaigns WHERE id=1;` затем в каждой `UPDATE campaigns SET spent_today = <прочитанное> + 100 WHERE id=1; COMMIT;`. Итог - потеряно 100. Исправьте: `SELECT ... FOR UPDATE` или атомарный `UPDATE ... SET spent_today = spent_today + 100`.

**D. Deadlock.** Сессия 1: `START TRANSACTION; UPDATE campaigns SET name=name WHERE id=1;`. Сессия 2: `START TRANSACTION; UPDATE campaigns SET name=name WHERE id=2;`. Сессия 1: `UPDATE ... WHERE id=2;` (ждёт). Сессия 2: `UPDATE ... WHERE id=1;` -> `ERROR 1213 (40001): Deadlock found`. Посмотреть: `SHOW ENGINE INNODB STATUS\G` (раздел LATEST DETECTED DEADLOCK). Лечение: единый порядок обращения к строкам, ретрай.

**E. Gap lock (проверено на этом стенде).** Демо-таблица:
```sql
CREATE TABLE gap_demo (id INT PRIMARY KEY, v INT);
INSERT INTO gap_demo VALUES (10,1),(20,2),(30,3);
```
Сессия 1: `START TRANSACTION; SELECT * FROM gap_demo WHERE id BETWEEN 15 AND 25 FOR UPDATE;` (строк в диапазоне одна - 20, но блокируются и «дырки» вокруг).
Сессия 2: `INSERT INTO gap_demo VALUES (12, 0);` - **зависнет** и через `innodb_lock_wait_timeout` (50 секунд по умолчанию) упадёт с `ERROR 1205`.
Теперь повторите обе сессии, начав каждую с `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;` - вставка **проходит**. Вывод: при REPEATABLE READ InnoDB блокирует не только строки, но и промежутки (next-key locks), чтобы не допустить фантомов; при READ COMMITTED промежутки не блокируются. Вопрос себе: чем это опасно для параллельных вставок и откуда берутся неожиданные deadlock?
Не забудьте `DROP TABLE gap_demo;`.

**F. Что происходит на диске.** `SHOW INDEX FROM events;`, `SHOW CREATE TABLE events\G` - найти кластерный индекс (первичный ключ). Почему длинный или случайный PK раздувает вторичные индексы?

**G. Медленный лог.** Сервер запущен с `slow_query_log=1` и `long_query_time=0.2`. После нескольких тяжёлых запросов: `SHOW VARIABLES LIKE 'slow_query_log_file';` и прочитать файл через `docker compose exec db tail /var/lib/mysql/*-slow.log`.
