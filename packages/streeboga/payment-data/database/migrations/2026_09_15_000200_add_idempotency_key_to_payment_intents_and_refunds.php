<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключ идемпотентности из заголовка Idempotency-Key.
 *
 * Уникален в пределах мерчанта: одинаковые ключи разных мерчантов не пересекаются.
 * NULL в уникальном индексе не конфликтует ни в Postgres, ни в sqlite — запросы без
 * заголовка индекс не трогает.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_intents', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('idempotency_key')->nullable();
                $table->unique(['merchant_account_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        foreach (['payment_intents', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropUnique(['merchant_account_id', 'idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
