<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 家庭户：同一住址+户主归为一户，一户多名成员重复申请时合并到同一 household。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('address')->index()->comment('标准化住址');
            $table->string('address_raw')->comment('居民填写的原始住址');
            $table->string('household_head_name')->comment('户主姓名');
            $table->string('household_head_idcard', 18)->comment('户主身份证号');
            $table->unsignedBigInteger('merged_into_id')->nullable()->comment('重复申请合并目标户');
            $table->timestamps();

            $table->index(['address', 'household_head_idcard']);
            $table->unique(['address', 'household_head_idcard'], 'uniq_household_address_head');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('employee_no')
                ->constrained()->nullOnDelete();
            $table->string('idcard', 18)->nullable()->after('household_id');
            $table->boolean('is_household_head')->default(false)->after('idcard');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn(['idcard', 'is_household_head']);
        });
        Schema::dropIfExists('households');
    }
};
