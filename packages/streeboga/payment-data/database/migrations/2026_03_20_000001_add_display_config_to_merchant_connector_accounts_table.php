<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_connector_accounts', function (Blueprint $table) {
            $table->json('display_config')->nullable()->after('payment_methods_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_connector_accounts', function (Blueprint $table) {
            $table->dropColumn('display_config');
        });
    }
};
