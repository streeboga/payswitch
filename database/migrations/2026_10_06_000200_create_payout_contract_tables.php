<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контракт выплаты: только новые таблицы, существующие не трогаются.
 *
 * Маршрутов исполнения и адаптера канала нет — таблицы пусты, пока их не появится.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_recipient_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->string('project_id', 128);
            $table->string('recipient_reference', 128);
            $table->string('verification_reference', 128);
            $table->string('verification_purpose', 40)->nullable();
            $table->text('details');
            $table->string('details_hash', 64);
            $table->timestamp('created_at');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->string('project_id', 128);
            $table->string('operation_id', 128);
            $table->string('settlement_id', 128);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->foreignId('recipient_version_id')->constrained('payout_recipient_versions');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('precision');
            $table->string('channel', 128);
            $table->string('purpose', 1000);
            $table->string('status', 40);
            $table->string('approved_by', 128)->nullable();
            $table->string('authorization_reference', 128)->nullable();
            $table->string('reserve_reference', 128)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_account_id', 'project_id', 'idempotency_key'], 'payout_idempotency_unique');
            $table->unique(['merchant_account_id', 'project_id', 'operation_id'], 'payout_operation_unique');
        });

        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->foreignId('payout_id')->constrained('payouts');
            $table->string('channel', 128);
            $table->string('provider_reference', 128)->nullable();
            $table->string('status', 40);
            $table->timestamps();
        });

        Schema::create('payout_external_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('payouts');
            $table->string('event_id', 128);
            $table->string('attempt_key', 40);
            $table->boolean('execution_absent');
            $table->string('request_hash', 64);
            $table->string('status', 40);
            $table->string('provider_reference', 128);
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('recipient_version_key', 40);
            $table->string('evidence_reference', 1000);
            $table->string('related_reference', 128)->nullable();
            $table->unsignedTinyInteger('precision');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->unique(['payout_id', 'event_id']);
        });

        Schema::create('payout_external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_account_id')->constrained('merchant_accounts');
            $table->string('channel', 128);
            $table->string('provider_reference', 128);
            $table->foreignId('payout_id')->constrained('payouts');
            $table->unique(['merchant_account_id', 'channel', 'provider_reference'], 'payout_external_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_external_references');
        Schema::dropIfExists('payout_external_events');
        Schema::dropIfExists('payout_attempts');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_recipient_versions');
    }
};
