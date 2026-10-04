<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 批次资格规则快照：规则一旦冻结，后续审核（含补报）一律按此快照判断，不随现行规则变动。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->integer('low_temp_threshold')->comment('触发补贴的最低气温(℃)，如 -5');
            $table->json('allowed_heating_methods')->comment('允许的供暖方式: 集中供暖/自采暖/电暖/无供暖');
            $table->integer('doc_valid_days')->comment('材料有效天数，如居住证明 90 天内有效');
            $table->json('required_documents')->comment('必备材料清单 code 列表');
            $table->decimal('subsidy_amount', 10, 2)->comment('每户补贴金额');
            $table->string('subsidy_unit', 10)->default('户');
            $table->string('residency_requirement')->default('本社区户籍或连续居住满6个月');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_rules');
    }
};
