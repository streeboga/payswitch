<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('payment_intent_id')->constrained('payment_intents');
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->foreignId('business_profile_id')->nullable()->constrained('business_profiles');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->text('reason')->nullable();
            $table->string('connector')->nullable();
            $table->string('connector_refund_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
