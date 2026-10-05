<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хэш тела запроса рядом с ключом идемпотентности: повтор с тем же ключом и другим
 * телом отличим от честного повтора (PAYSWITCH_IDEMPOTENCY_COMPARE_REQUEST_HASH).
 *
 * Только nullable-колонка без значения по умолчанию и без индекса: на Postgres это
 * правка каталога, таблица не переписывается. У старых строк хэша нет — их повтор
 * сравнивается по-прежнему, по сумме.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_intents', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('request_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['payment_intents', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('request_hash');
            });
        }
    }
};
