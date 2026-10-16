-- Задача 02: топ-3 кампании по кликам за каждый из трёх последних полных дней (условие: sql/TASKS.md)
-- Время - от @now, а не от NOW().
SET @now = (SELECT MAX(created_at) FROM events);

-- Ваш запрос ниже:

SELECT *
FROM (SELECT t.*,
             RANK() OVER (PARTITION BY t.day ORDER BY t.clicks DESC) AS place
      FROM (SELECT DATE(created_at)                           AS day,
                   campaign_id,
                   COUNT(campaign_id) AS clicks
      FROM events
      WHERE created_at >= DATE_SUB(DATE(@now), INTERVAL 3 DAY)
        AND created_at < DATE(@now) AND  type = 'click'
      GROUP BY day, campaign_id) AS t) AS t2
WHERE t2.place <= 3
ORDER BY t2.day, t2.place, t2.campaign_id;   -- сортировка результата - только во внешнем SELECT


-- Ответ на вопрос (ROW_NUMBER / RANK / DENSE_RANK: сколько строк и почему):
-- Проверено подстановкой каждой функции в этот же запрос.
--
--   ROW_NUMBER -> 9 строк.  Номера всегда разные (1, 2, 3, ...), ничьи не учитываются: в каждом дне ровно 3 строки.
--                           При равных кликах кто получит 2-е, а кто 3-е место - не определено.
--                           Пример 2026-10-12: из трёх кампаний с 49 кликами (47, 66, 198) вошли 66 и 198, а 47 выпала
--                           случайно. Чтобы результат был стабильным, нужен «тай-брейкер»: ORDER BY clicks DESC, campaign_id.
--   RANK       -> 14 строк. Ничья - одинаковое место, следующие места пропускаются (1, 2, 2, 2, 5).
--                           2026-10-12: трое делят 2-е место, 3-го места нет. Это и есть «как в спорте» из условия.
--   DENSE_RANK -> 16 строк. Ничья - одинаковое место, но без пропусков (1, 2, 2, 2, 3).
--                           2026-10-12: добавляются ещё две кампании с 48 кликами на 3-м месте.
--
-- Кратко: ROW_NUMBER - «ровно N строк», RANK - «места как в спорте», DENSE_RANK - «N разных значений».
-- Ещё: окно вычисляется ПОСЛЕ WHERE/GROUP BY, поэтому фильтр по place делают во внешнем запросе (или в CTE),
-- а не в WHERE того же SELECT.
