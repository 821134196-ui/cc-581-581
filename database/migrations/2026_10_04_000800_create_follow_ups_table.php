<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补件与申诉：沿原申请跟进（application_id 不变），不产生新批次、不换新申请。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['supplement', 'appeal'])->comment('补件/申诉');
            $table->foreignId('user_id')->constrained('users')->comment('发起人');
            $table->text('reason')->nullable()->comment('申诉理由 / 补件说明');
            $table->json('extra_payload')->nullable()->comment('补件附带的更新资料');
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->text('handle_comment')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
