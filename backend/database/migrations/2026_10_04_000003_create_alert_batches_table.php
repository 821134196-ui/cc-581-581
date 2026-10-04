<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 低温预警批次：资格规则在批次上定义，申请时整体快照进 applications.rule_snapshot
        Schema::create('alert_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('level', 16)->default('blue'); // 蓝/黄/橙/红
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();     // 预警结束时间，null=预警进行中
            $table->boolean('is_accepting')->default(true);
            $table->date('late_filing_deadline')->nullable(); // 预警结束后补报截止日
            $table->integer('subsidy_amount');               // 单位：分
            // 规则：required_documents / eligible_heating_methods 等
            $table->json('rules');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_batches');
    }
};
