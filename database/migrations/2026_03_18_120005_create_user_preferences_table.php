<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('UTC');
            $table->string('date_format')->default('YYYY-MM-DD');
            $table->string('number_format')->default('en-US');
            $table->string('base_currency', 3)->default('USD');
            $table->string('theme')->default('auto');
            $table->string('data_density')->default('comfortable');
            $table->boolean('notification_email')->default(true);
            $table->boolean('notification_inapp')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
