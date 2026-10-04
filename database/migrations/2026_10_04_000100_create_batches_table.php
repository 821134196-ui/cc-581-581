<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 低温预警批次：按预警受理，规则随批次冻结，预警结束后仍可补报但必须沿用本批规则。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('批次编号，如 2026-W01');
            $table->string('title');
            $table->date('warning_start')->comment('预警开始日');
            $table->date('warning_end')->comment('预警结束日');
            $table->date('apply_deadline')->comment('正常受理截止日');
            $table->date('late_apply_deadline')->comment('预警结束后补报截止日');
            $table->boolean('rules_frozen')->default(false)->comment('规则是否已冻结，冻结后不可修改');
            $table->date('rules_frozen_at')->nullable();
            $table->unsignedBigInteger('rules_frozen_by')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
