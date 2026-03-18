<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts')->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('type');
            $table->string('status');
            $table->string('reason_code')->nullable();
            $table->text('reason_description')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
