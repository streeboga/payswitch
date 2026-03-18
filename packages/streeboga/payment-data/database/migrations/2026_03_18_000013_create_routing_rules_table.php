<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('merchant_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->string('type'); // 'priority', 'volume_split', 'rule_based'
            $table->string('name');
            $table->json('rules'); // routing rules configuration
            $table->boolean('active')->default(true);
            $table->integer('priority')->default(0); // higher = applied first
            $table->timestamps();

            $table->index(['merchant_account_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
