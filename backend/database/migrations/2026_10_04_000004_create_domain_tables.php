<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();
            // submitted 待初审 | supplementing 待补件 | community_rejected 初审不通过
            // pending_street 待复核 | street_rejected 复核不通过 | approved 已核准入清单
            // paid 已到账 | merged 已并入其他申请
            $table->string('status', 24)->default('submitted')->index();

            $table->boolean('is_late')->default(false);          // 预警结束后的补报
            $table->json('rule_snapshot');                       // 申请时的本批资格规则快照
            $table->json('payload');                             // 申请时资料：供暖方式、备注等

            // 收款信息（申请时填写，冻结/核准后变更须重新核验）
            $table->string('payee_name');
            $table->string('payee_account');
            $table->string('payee_bank')->nullable();
            $table->boolean('payee_verified')->default(true);

            $table->boolean('frozen')->default(false);           // 申请冻结
            $table->foreignId('merged_into_id')->nullable()
                  ->constrained('applications')->nullOnDelete();

            $table->timestamps();
            $table->index(['alert_batch_id', 'household_id']);
        });

        // 申请-成员 关联（一户多人申请时，所有享受补贴成员都挂在申请上）
        Schema::create('application_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'member_id']);
        });

        // 材料：提交申请时按批次规则逐份生成，required=缺，submitted=已交，expired=过期
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type');
            $table->string('label');
            $table->string('status', 16)->default('required'); // required/submitted/expired
            $table->string('file_ref')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'doc_type']);
        });

        // 申诉：始终挂在原申请上，不产生新申请、不换批次
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('pending'); // pending/accepted/rejected
            $table->foreignId('handler_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reply')->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->timestamps();
        });

        // 审核记录：community=社区初审，street=街道复核
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 16);
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 16); // approve/return_supplement/reject
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 收款人变更申请（冻结或核准后变更须街道重新核验）
        Schema::create('payee_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('payee_name');
            $table->string('payee_account');
            $table->string('payee_bank')->nullable();
            $table->string('status', 16)->default('pending'); // pending/verified/rejected
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 发放清单（每户一笔；回执到账后置 paid）
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('status', 16)->default('pending'); // pending/paid
            $table->string('transfer_ref')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
            $table->unique('application_id');
        });

        // 支付回执：receipt_no 唯一，重复送达只记一次
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_no')->unique();
            $table->string('outcome', 16)->default('success');
            $table->json('payload')->nullable();
            $table->unsignedInteger('redelivery_count')->default(0);
            $table->timestamps();
        });

        // 本地模拟短信
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->text('content');
            $table->string('tag', 32)->nullable();
            $table->timestamps();
            $table->index('phone');
        });

        // 申请全程事件流（状态变更、补件、申诉、合并、冻结、到账……）
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payee_change_requests');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('application_member');
        Schema::dropIfExists('applications');
    }
};
