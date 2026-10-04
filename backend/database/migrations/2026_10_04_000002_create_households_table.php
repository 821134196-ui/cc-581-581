<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 住户（按住址归一化唯一）
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('address');
            $table->string('address_hash')->unique();
            $table->timestamps();
        });

        // 居民成员（一户可有多名成员，成员可关联登录账号）
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('id_number');
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->unique('id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
        Schema::dropIfExists('households');
    }
};
