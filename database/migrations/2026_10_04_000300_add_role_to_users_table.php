<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户与角色：居民 / 社区初审员 / 街道复核员。
 * 初审员与复核员由 role 区分，同一申请初审与复核不得为同一人。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('resident')->after('password')->comment('resident|community|street');
            $table->string('phone', 20)->nullable()->after('role');
            $table->string('employee_no', 30)->nullable()->after('phone')->comment('工号');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'employee_no']);
        });
    }
};
