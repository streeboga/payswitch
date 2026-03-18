<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_country_code')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('default_payment_method_id')->nullable();
            $table->timestamps();

            $table->index('merchant_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
