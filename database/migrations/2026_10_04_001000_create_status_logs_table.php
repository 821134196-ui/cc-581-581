<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 申请进度时间线：居民可见的审核/到账/补件/申诉/冻结等全部节点。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('event')->comment('机器事件码: submitted/community_pass/receipt...');
            $table->text('message')->comment('面向居民的中文说明');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_logs');
    }
};
