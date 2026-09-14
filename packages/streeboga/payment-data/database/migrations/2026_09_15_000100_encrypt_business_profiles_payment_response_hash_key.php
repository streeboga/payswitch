<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ключ подписи исходящих вебхуков — под шифрованием на APP_KEY, как креды
 * коннекторов. Шифротекст длиннее 128 символов, поэтому колонка — text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->text('payment_response_hash_key')->nullable()->change();
        });

        $this->rewriteKeys(fn (string $key) => Crypt::encryptString($key));
    }

    public function down(): void
    {
        $this->rewriteKeys(fn (string $key) => Crypt::decryptString($key));

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('payment_response_hash_key', 128)->nullable()->change();
        });
    }

    /**
     * @param  Closure(string): string  $transform
     */
    private function rewriteKeys(Closure $transform): void
    {
        DB::table('business_profiles')
            ->whereNotNull('payment_response_hash_key')
            ->select(['id', 'payment_response_hash_key'])
            ->chunkById(100, function ($profiles) use ($transform) {
                foreach ($profiles as $profile) {
                    DB::table('business_profiles')
                        ->where('id', $profile->id)
                        ->update(['payment_response_hash_key' => $transform($profile->payment_response_hash_key)]);
                }
            });
    }
};
