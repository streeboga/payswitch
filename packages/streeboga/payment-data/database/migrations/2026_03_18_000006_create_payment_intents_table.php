<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->foreignId('business_profile_id')->nullable()->constrained('business_profiles');
            $table->bigInteger('amount');
            $table->bigInteger('net_amount')->nullable();
            $table->bigInteger('amount_capturable')->nullable();
            $table->bigInteger('amount_received')->nullable();
            $table->string('currency', 3);
            $table->string('status');
            $table->string('client_secret')->unique();
            $table->string('capture_method')->default('automatic');
            $table->string('authentication_type')->default('no_three_ds');
            $table->string('customer_id')->nullable();
            $table->string('return_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('connector')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->integer('session_expiry')->default(900);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('expires_on')->nullable();
            $table->timestamps();

            $table->index('merchant_account_id');
            $table->index('status');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
