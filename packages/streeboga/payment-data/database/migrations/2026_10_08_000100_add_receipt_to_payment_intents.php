<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кассовый чек (54-ФЗ): receipt — состав чека, который мерчант прислал с платежом
 * (уходит кассе провайдера); receipt_id и receipt_url — что касса выдала, из
 * уведомления о чеке. Nullable без индекса: на Postgres это правка каталога.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->json('receipt')->nullable();
            $table->string('receipt_id', 128)->nullable();
            $table->string('receipt_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', fn (Blueprint $table) => $table->dropColumn(['receipt', 'receipt_id', 'receipt_url']));
    }
};
