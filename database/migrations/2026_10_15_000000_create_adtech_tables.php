<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Деньги - целые числа в микро-единицах (1 000 000 = 1 у.е.). Никакого float.
 *
 * `events` намеренно БЕЗ вторичных индексов - это материал для SQL-тренировки (sql/drills.md).
 */
class CreateAdtechTables extends Migration
{
    public function up()
    {
        Schema::create('campaigns', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->unsignedBigInteger('daily_budget');
            $t->unsignedBigInteger('spent_today')->default(0);
            $t->string('status', 16)->default('active');   // active | paused
            $t->timestamps();
        });
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_spent_le_budget CHECK (spent_today <= daily_budget)');

        // Журнал списаний: по одному на показ. Уникальность impression_id = идемпотентность.
        Schema::create('charges', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('impression_id', 36)->unique();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('cost');
            $t->timestamp('created_at')->useCurrent();
            $t->index('campaign_id');
        });

        // Агрегат по часам: PK (campaign_id, hour) - цель для INSERT ... ON DUPLICATE KEY UPDATE.
        Schema::create('hourly_stats', function (Blueprint $t) {
            $t->unsignedBigInteger('campaign_id');
            $t->dateTime('hour');
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedBigInteger('spend')->default(0);
            $t->primary(['campaign_id', 'hour']);
        });

        // Сырые события для SQL-тренировки (заполняется `php artisan practice:seed-big`).
        Schema::create('events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('campaign_id');
            $t->unsignedBigInteger('user_id');
            $t->string('type', 12);                        // impression | click
            $t->unsignedInteger('cost')->default(0);
            $t->string('click_id', 36)->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('hourly_stats');
        Schema::dropIfExists('charges');
        Schema::dropIfExists('campaigns');
    }
}
