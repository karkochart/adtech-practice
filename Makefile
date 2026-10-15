# Все команды выполняются в контейнерах. Запуск: make <цель>
EXEC = docker compose exec php

up:             ## поднять php 7.3 + mysql 8 + redis
	docker compose up -d --build
down:
	docker compose down
test:           ## все тесты (MySQL, база adtech_test)
	$(EXEC) php artisan test
t:              ## один тест: make t F=BudgetServiceTest
	$(EXEC) php artisan test --filter=$(F)
mysql:          ## консоль MySQL
	docker compose exec db mysql -uadtech -padtech adtech
seed:           ## датасет для SQL-тренировки (2 млн событий)
	$(EXEC) php artisan practice:seed-big --force
sql-solutions:  ## прогнать эталонные SQL-решения
	docker compose exec db sh -c "mysql -uadtech -padtech adtech -t < /sql/solutions.sql"
sh:
	$(EXEC) bash
