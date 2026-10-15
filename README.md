# AdTech practice - тренажёр под высоконагруженную рекламную платформу

Стек повторяет вакансию: **PHP 7.3 + Laravel 8 + MySQL 8 + Redis**, Docker. Код совместим с PHP 7.3 (без типизированных свойств, стрелочных функций, `match`, named arguments).
Часть кода - заглушки, тесты красные: это задания.

| # | Задача | Где условие |
|---|--------|-------------|
| 1 | Списание бюджета кампании: идемпотентность по `impression_id`, без перерасхода при параллельных запросах | `tests/Feature/BudgetServiceTest.php`, `BudgetConcurrencyTest.php` |
| 2 | Пакетная агрегация статистики одним запросом `INSERT ... ON DUPLICATE KEY UPDATE` | `tests/Feature/StatsAggregatorTest.php` |
| 3 | Частотное ограничение показов на пользователя (атомарный счётчик с TTL) | `tests/Feature/FrequencyCapTest.php` |
| 4 | SQL-тренировка на 2 млн событий: индексы, EXPLAIN, окна, блокировки | `sql/drills.md`, решения `sql/solutions.sql` |

Эталонные решения задач 1-3 в репозиторий не входят (хранятся локально в `solutions/`), чтобы можно было решать заново.

```
make up        # php 7.3 + mysql 8 + redis; composer install + migrate
make test      # phpunit на MySQL (база adtech_test)
make seed      # датасет для SQL-заданий
make mysql     # консоль
```
Порты: приложение http://localhost:8020, MySQL 127.0.0.1:33061 (adtech/adtech).
