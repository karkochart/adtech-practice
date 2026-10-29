<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOutboxTables extends Migration
{
    public function up()
    {
        // Исходящие сообщения: пишутся в той же транзакции, что и бизнес-данные, отправляются отдельным процессом.
        Schema::create('outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('message_key', 64)->unique();   // ключ дедупликации: одно сообщение на одно бизнес-событие
            $t->string('topic', 64);
            $t->text('payload');                        // JSON
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();       // NULL - ещё не отправлено
            $t->index(['sent_at', 'id']);
        });

        // Журнал стороны-получателя (условный сервис биллинга).
        Schema::create('billing_entries', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('message_key', 64)->unique();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('amount');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('billing_entries');
        Schema::dropIfExists('outbox');
    }
}
