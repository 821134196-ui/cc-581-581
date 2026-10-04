<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 模拟支付服务的交互留痕：发起支付、送达回执。
 * receipt_no 为外部回执幂等键；同一回执重复送达只入账一次。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['outgoing', 'incoming'])->comment('发起/回执');
            $table->string('payment_no')->nullable()->index();
            $table->string('receipt_no')->nullable()->index();
            $table->foreignId('disbursement_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->enum('result', ['accepted', 'duplicate', 'recorded', 'ignored'])->nullable()
                ->comment('accepted=新支付受理, duplicate=重复回执, recorded=首次回执入账');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
