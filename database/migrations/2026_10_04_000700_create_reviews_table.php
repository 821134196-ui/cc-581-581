<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 审核记录：社区初审与街道复核两级。
 * 同一申请的初审员不得是复核员（业务层强校验，状态字段保留双人痕迹）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['community', 'street'])->comment('初审/复核');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->enum('result', ['pass', 'reject', 'supplement', 'reverify'])->comment('通过/驳回/补件/重新核验');
            $table->text('comment')->nullable();
            $table->json('checked_items')->nullable()->comment('核验项快照: 供暖方式/材料有效性/资格...');
            $table->timestamps();

            $table->index(['application_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
