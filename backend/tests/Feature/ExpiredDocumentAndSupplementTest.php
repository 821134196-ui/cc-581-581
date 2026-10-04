<?php

namespace Tests\Feature;

use App\Models\Application;
use Illuminate\Support\Carbon;

/**
 * 场景三：材料过期提示补充 + 补件/申诉沿原申请跟进。
 */
class ExpiredDocumentAndSupplementTest extends SubsidyTestCase
{
    public function test_提交已过期材料直接标expired且资格不通过(): void
    {
        Carbon::setTestNow('2026-01-06 10:00:00');
        $batch = $this->batch();
        $resident = $this->resident();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        $this->actingAs($resident)->postJson("/api/applications/{$appId}/documents", [
            'doc_type' => 'heating_bill',
            'expires_at' => '2025-12-31',
        ])->assertOk();

        $detail = $this->actingAs($resident)->getJson("/api/applications/{$appId}")->json('data');
        $bill = collect($detail['documents'])->firstWhere('doc_type', 'heating_bill');
        $this->assertSame('expired', $bill['status']);
        $this->assertFalse($detail['eligibility']['eligible']);
        $this->assertTrue(str_contains(implode('', $detail['eligibility']['reasons']), '过期'));
        Carbon::setTestNow();
    }

    public function test_定时扫描把过期材料标退件并提示居民补充(): void
    {
        Carbon::setTestNow('2026-01-06 10:00:00');
        $batch = $this->batch();
        $resident = $this->resident();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');
        // 材料当时有效
        $this->submitAllDocs(Application::find($appId), $resident,
            ['heating_bill' => '2026-02-01']);

        // 时间流逝，凭证过期；申请已在街道复核队列
        $community = $this->community();
        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertOk();

        Carbon::setTestNow('2026-03-01 09:00:00');
        $this->actingAs($this->street())->postJson('/api/maintenance/sweep-expired')
            ->assertOk();

        $app = Application::with('documents')->find($appId);
        $this->assertSame('supplementing', $app->status, '在途申请因材料过期应退回补件');
        $this->assertSame('expired', $app->documents->firstWhere('doc_type', 'heating_bill')->status);

        // 短信已通知居民
        $this->assertDatabaseHas('sms_messages', [
            'phone' => '13800000001',
            'tag' => 'doc_expired',
        ]);

        // 居民沿原申请补交，不产生新申请、批次不变
        $this->actingAs($resident)->postJson("/api/applications/{$appId}/documents", [
            'doc_type' => 'heating_bill',
            'expires_at' => '2027-03-01',
        ])->assertOk();

        $app->refresh();
        $this->assertSame('submitted', $app->status, '材料补齐后重新进入初审队列');
        $this->assertSame($batch->id, $app->alert_batch_id);
        $this->assertSame(1, Application::count());
        Carbon::setTestNow();
    }

    public function test_补件始终沿原申请且不换批次(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $community = $this->community();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        // 社区退回补件
        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'return_supplement', 'note' => '缺取暖凭证'])
            ->assertOk();
        $this->assertSame('supplementing', Application::find($appId)->status);

        // 补交材料沿原申请
        $this->submitAllDocs(Application::find($appId), $resident);
        $this->assertSame('submitted', Application::find($appId)->status);
        $this->assertSame(1, Application::count());
    }

    public function test_申诉沿原申请跟进且申诉成立后重新审核(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $community = $this->community();
        $street = $this->street();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'reject'])->assertOk();
        $this->assertSame('community_rejected', Application::find($appId)->status);

        // 居民沿原申请申诉
        $this->actingAs($resident)->postJson("/api/applications/{$appId}/appeals", [
            'reason' => '低保证在年审中',
        ])->assertCreated();

        $appealId = \App\Models\Appeal::firstOrFail()->id;
        $this->actingAs($street)->postJson("/api/appeals/{$appealId}/handle", [
            'accepted' => true,
            'reply' => '同意宽限',
        ])->assertOk();

        $app = Application::find($appId);
        $this->assertSame('submitted', $app->status, '申诉成立后退回社区初审队列');
        $this->assertSame($batch->id, $app->alert_batch_id, '批次不变');
        $this->assertSame(1, Application::count(), '不产生新申请');
    }

    public function test_非本人不能查看他人申请(): void
    {
        $batch = $this->batch();
        $a = $this->resident('13800000001', '甲');
        $other = $this->resident('13800000009', '乙');

        $appId = $this->actingAs($a)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        $this->actingAs($other)->getJson("/api/applications/{$appId}")
            ->assertStatus(403);

        $list = $this->actingAs($other)->getJson('/api/my/applications')->json('data');
        $this->assertSame([], $list);
    }

    public function test_同户成员可只读查看但不能补件或申诉(): void
    {
        $batch = $this->batch();
        $a = $this->resident('13800000001', '张建国');
        $b = $this->resident('13800000002', '李秀兰');

        $appId = $this->actingAs($a)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        // 李秀兰并入同户申请（她的成员资料随之挂到原申请）
        $this->actingAs($b)->postJson('/api/applications', $this->applyPayload($batch->id))
            ->assertStatus(409);
        $this->actingAs($b)->postJson('/api/applications', $this->applyPayload($batch->id, [
            'merge_application_id' => $appId,
            'members' => [['name' => '李秀兰', 'id_number' => 'ID-LXL']],
        ]))->assertCreated();

        // 同户成员可查看审核与到账记录
        $this->actingAs($b)->getJson("/api/applications/{$appId}")
            ->assertOk()
            ->assertJsonPath('data.id', $appId);

        // 她的列表中：原申请（作为同户成员）+ 合并存根（状态 merged，指向原申请）
        $myList = $this->actingAs($b)->getJson('/api/my/applications')->json('data');
        $this->assertCount(2, $myList);
        $this->assertNotNull(collect($myList)->firstWhere('id', $appId));
        $stub = collect($myList)->firstWhere('status', 'merged');
        $this->assertSame($appId, $stub['merged_into_id']);

        // 但不能补件、不能申诉（只有申请人本人可写）
        $this->actingAs($b)->postJson("/api/applications/{$appId}/documents", [
            'doc_type' => 'id_card',
        ])->assertStatus(403);
        $this->actingAs($b)->postJson("/api/applications/{$appId}/appeals", [
            'reason' => '试试',
        ])->assertStatus(403);
    }
}
