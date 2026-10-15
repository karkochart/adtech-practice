-- Задача 01: активные кампании и активность за последние 3 минуты (условие: sql/TASKS.md)
SET @now = (SELECT MAX(created_at) FROM events);

-- Ваш запрос ниже:

