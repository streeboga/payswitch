<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бизнес-поля мерчанта: чей это платёж (project_id), какой операции (operation_id) и
 * какого заказа (order_id). Payswitch их не толкует — хранит и возвращает в ответах и
 * событиях. Nullable без индекса: на Postgres это правка каталога.
 */
return new class extends Migration
{
    private const COLUMNS = ['project_id', 'operation_id', 'order_id'];

    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 128)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
