<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补贴申请：以户为单位提交，冻结批次的规则快照，保存申请时提交的资料快照。
 * 同一批次同一户只允许一份有效申请。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('apply_no')->unique()->comment('申请编号');
            $table->foreignId('batch_id')->constrained();
            $table->foreignId('household_id')->constrained();
            $table->foreignId('applicant_id')->constrained('users')->comment('提交申请人');
            $table->json('rule_snapshot')->comment('提交时资格规则快照(冻结)');
            $table->json('payload_snapshot')->comment('申请资料快照: 住址/供暖方式/成员/收款信息');
            $table->string('heating_method')->comment('供暖方式');
            $table->string('address');
            $table->boolean('is_late')->default(false)->comment('是否预警结束后补报');
            $table->enum('status', [
                'draft',                 // 草稿
                'submitted',             // 已提交待初审
                'community_pending',     // 待社区初审（缺件待补时回到此状态）
                'supplement_requested',  // 要求补件
                'community_rejected',    // 初审不通过
                'street_pending',        // 待街道复核
                'approved',              // 复核通过，进入发放清单
                'rejected',              // 复核不通过
                'appealing',             // 申诉中
                'frozen',                // 冻结（收款人变更等需重新核验）
                'disbursed',             // 已发放到账
            ])->default('draft')->index();
            $table->unsignedBigInteger('frozen_by')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->string('freeze_reason')->nullable();
            $table->boolean('merged')->default(false)->comment('是否为重复申请已被合并');
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->string('status_before_freeze')->nullable()->comment('冻结前状态，解冻/重核后恢复');
            $table->boolean('reverify_required')->default(false)->comment('收款人变更后是否需要街道重新核验');
            $table->timestamps();

            $table->index(['batch_id', 'household_id']);
            // “同批次同户仅一份有效申请”由业务层在事务中保证
            // （已合并的重复申请 merged=true 可有多条，不适合用普通复合唯一索引）。
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
