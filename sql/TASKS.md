# SQL-задачи (MySQL 8.0, база `adtech`, 2 млн событий)

**Как работаем.** Я даю по одной задаче. Ответ вы пишете в файл `sql/answers/NN.sql` (шаблон уже лежит),
запускаете `make sql-run F=NN` и пишете мне «готово». Я прогоню ваш запрос на данных, сверю с эталоном и добавлю сюда разбор.
К любой задаче можно вернуться: условие и ваш файл остаются, статус меняется на ✅ только после проверки.
Статусы: ⬜ не решена · ✅ решена самостоятельно · 🔁 решение показано, вернуться через пару дней и решить с нуля.

Данные: `make seed` (если таблицы пустые). Консоль: `make mysql`.
Таблицы: `events(id, campaign_id, user_id, type 'impression'|'click', cost, click_id, created_at)`,
`campaigns(id, name, daily_budget, spent_today, status 'active'|'paused')`, `charges`, `hourly_stats`.
Опорное время для «последних N минут»: `SET @now = (SELECT MAX(created_at) FROM events);` - не `NOW()`:
данные сгенерированы один раз, а `NOW()` со временем уезжает от них.

| # | Тема | Статус |
|---|------|--------|
| 01 | LEFT JOIN, условная агрегация, нули | 🔁 решение показано, решить заново самому |

---

## 01. Активные кампании и их активность за последний час

**Условие.** Для **каждой активной** кампании (`campaigns.status = 'active'`) вывести:
`campaign_id`, `name`, `impressions`, `clicks` - число событий типа `impression` и `click` за **последние 60 минут**
относительно `@now` (то есть `created_at > @now - INTERVAL 60 MINUTE`).

- Кампании, у которых за этот период **нет ни одного события**, тоже должны быть в результате, с нулями.
- Сортировка: `impressions` по возрастанию, затем `campaign_id` по возрастанию.
- В ответе только первые **10** строк (`LIMIT 10`).
- Типы: `impressions` и `clicks` - целые числа, не `NULL`.

**Проверка себе.** Без `LIMIT` строк должно быть ровно столько, сколько активных кампаний (`SELECT COUNT(*) FROM campaigns WHERE status='active'` - сейчас 195).
В данных есть 5 новых кампаний (`new-campaign-1..5`), у которых **нет ни одного события** - в правильном ответе они наверху с нулями.

**Файл:** `sql/answers/01.sql` · **Запуск:** `make sql-run F=01`

**Разбор** (решение показано 16.10 - через пару дней решить с нуля, не подглядывая):

```sql
SET @now = (SELECT MAX(created_at) FROM events);
SELECT
    c.id AS campaign_id,
    c.name,
    COUNT(CASE WHEN e.type = 'impression' THEN 1 END) AS impressions,
    COUNT(CASE WHEN e.type = 'click' THEN 1 END) AS clicks
FROM campaigns AS c
LEFT JOIN events AS e
       ON e.campaign_id = c.id
      AND e.created_at > @now - INTERVAL 60 MINUTE
WHERE c.status = 'active'
GROUP BY c.id, c.name
ORDER BY impressions, c.id
LIMIT 10;
```

Ошибки, на которых споткнулись:
1. `now()` вместо `@now` - данные старые, окно от «сейчас» пустое.
2. `COUNT(CASE ... THEN 1 ELSE 0 END)` - `COUNT` считает всё, что не `NULL`, а `0` не `NULL`: получалось «показы = клики = все строки». Нужно без `ELSE` (будет `NULL`) или `SUM(...)` + `COALESCE`.
3. `JOIN` теряет кампании без событий (190 строк вместо 195).
4. При `LEFT JOIN` условие на правую таблицу в `WHERE` снова превращает его в обычный `JOIN`: `NULL > x` не истина. Условия на правую таблицу - в `ON`, на левую - в `WHERE`.
5. `campaign_id` - из `campaigns`, не из `events` (у кампаний без событий там `NULL`).

Для интервью: «условие на присоединяемую таблицу в `WHERE` убивает `LEFT JOIN`» и «`COUNT(expr)` считает не-`NULL`» - классические вопросы.
