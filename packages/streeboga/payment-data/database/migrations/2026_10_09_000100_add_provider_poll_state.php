<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Опрос провайдера по расписанию (PollProviderStatusJob): сколько раз уже спросили без
 * итога и когда спросить снова. У платежа обнуляется при смене статуса.
 *
 * ponytail: без индекса — выборка идёт по статусу, строк «в обработке» единицы; индекс
 * по next_poll_at, если их станут тысячи.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_intents', 'refunds'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedSmallInteger('poll_attempts')->default(0);
                $table->timestamp('next_poll_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['payment_intents', 'refunds'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['poll_attempts', 'next_poll_at']));
        }
    }
};
