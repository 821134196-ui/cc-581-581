<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 申请材料：记录每份材料的提交时间与到期日。
 * 材料过期（提交时间 + 规则 doc_valid_days < 审核/检查日）时提示补充。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type')->comment('材料类型 code: residence/idcard/bankcard/low_income...');
            $table->string('doc_name');
            $table->string('file_path')->nullable()->comment('模拟文件路径');
            $table->date('issued_on')->nullable()->comment('材料签发日');
            $table->date('expire_on')->comment('到期日(按申请批次规则算出)');
            $table->enum('status', [
                'submitted',      // 已提交
                'expired',        // 已过期需补
                'resubmitted',    // 已补交
                'waived',         // 免交
            ])->default('submitted');
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
