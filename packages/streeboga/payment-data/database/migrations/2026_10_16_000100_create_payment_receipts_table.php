<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус фискального чека — отдельно от статуса платежа: успех у PSP не значит успех чека.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->string('connector', 64);
            $table->string('kind', 16)->default('sell');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('total');
            $table->string('provider_reference', 128)->nullable();
            $table->string('fiscal_url', 512)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_intent_id', 'kind']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
