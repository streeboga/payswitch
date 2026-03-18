<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('publishable_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_accounts');
    }
};
