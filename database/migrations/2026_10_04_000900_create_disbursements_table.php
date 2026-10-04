<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 发放清单：街道复核通过后形成，一批次一行一户。
 * payment_no 由本地模拟支付服务返回；回执按 receipt_no 幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained();
            $table->foreignId('application_id')->constrained();
            $table->foreignId('household_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->string('payee_name')->comment('收款人姓名(发放时快照)');
            $table->string('payee_account')->comment('收款账户(发放时快照)');
            $table->enum('status', [
                'listed',       // 已入发放清单
                'paying',       // 已向支付服务发起
                'paid',         // 回执确认到账
                'failed',       // 支付失败
                'frozen',       // 因申请冻结暂停
            ])->default('listed')->index();
            $table->string('payment_no')->nullable()->comment('支付服务单号');
            $table->string('receipt_no')->nullable()->unique()->comment('支付回执号(幂等键)，重复回执只记一次');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('receipt_count')->default(0)->comment('回执送达次数(含重复)');
            $table->timestamps();

            $table->unique(['batch_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
