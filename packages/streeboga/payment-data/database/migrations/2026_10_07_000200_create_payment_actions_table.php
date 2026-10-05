<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Проведённые захваты и отмены с ключом идемпотентности вызывающего: повтор запроса с
 * тем же Idempotency-Key находит здесь своё действие и не делает второго.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_intent_id')->constrained('payment_intents');
            $table->string('idempotency_key');
            $table->string('action', 16);
            $table->unsignedBigInteger('amount')->nullable();
            $table->timestamps();

            $table->unique(['payment_intent_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_actions');
    }
};
