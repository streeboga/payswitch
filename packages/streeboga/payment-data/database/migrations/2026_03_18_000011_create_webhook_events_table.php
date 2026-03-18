<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('event_type');
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->foreignId('business_profile_id')->nullable()->constrained('business_profiles');
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents');
            $table->json('content');
            $table->boolean('delivered')->default(false);
            $table->integer('delivery_attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
