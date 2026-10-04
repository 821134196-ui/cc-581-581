<?php

namespace Tests\Feature;

use App\Models\Payment;

/**
 * 场景一：一户多名成员重复申请 → 提示合并，补贴按户只发一次。
 */
class HouseholdMergeTest extends SubsidyTestCase
{
    public function test_同户第二人申请时返回409合并提示(): void
    {
        $batch = $this->batch();
        $a = $this->resident('13800000001', '张建国');
        $b = $this->resident('13800000002', '李秀兰');

        $this->actingAs($a)->postJson('/api/applications', $this->applyPayload($batch->id, [
            'members' => [['name' => '张建国', 'id_number' => 'ID-ZJG']],
        ]))->assertCreated();

        // 地址带空格差异，也应归一化为同一户
        $res = $this->actingAs($b)->postJson('/api/applications', $this->applyPayload($batch->id, [
            'address' => ' 幸福里 3 栋 201 ',
            'members' => [['name' => '李秀兰', 'id_number' => 'ID-LXL']],
        ]));
        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'DUPLICATE_IN_HOUSEHOLD');
        $this->assertSame('幸福里3栋201', $res->json('existing_applications.0.address')
            ?? \App\Models\Household::first()->address);
    }

    public function test_确认并入后成员挂到原申请且不产生第二笔补贴(): void
    {
        $batch = $this->batch();
        $a = $this->resident('13800000001', '张建国');
        $b = $this->resident('13800000002', '李秀兰');
        $community = $this->community();
        $street = $this->street();

        $firstId = $this->actingAs($a)->postJson('/api/applications', $this->applyPayload($batch->id))
            ->json('application_id');

        // 第二人先碰 409，再带 merge_application_id 确认并入
        $this->actingAs($b)->postJson('/api/applications', $this->applyPayload($batch->id))
            ->assertStatus(409);

        $this->actingAs($b)->postJson('/api/applications', $this->applyPayload($batch->id, [
            'merge_application_id' => $firstId,
            'members' => [['name' => '李秀兰', 'id_number' => 'ID-LXL']],
        ]))->assertCreated()->assertJson(['merged' => true, 'application_id' => $firstId]);

        $first = \App\Models\Application::with('members')->find($firstId);
        $this->assertCount(2, $first->members);
        $this->assertSame(['张三', '李秀兰'], $first->members->pluck('name')->sort()->values()->all());

        // 合并留下的第二条申请记录状态为 merged，并指向原申请
        $stub = \App\Models\Application::where('status', 'merged')->first();
        $this->assertNotNull($stub);
        $this->assertSame($firstId, $stub->merged_into_id);

        // 走完审核发放，只有一笔 payment
        $this->submitAllDocs($first->fresh(), $a);
        $this->actingAs($community)->postJson("/api/applications/{$firstId}/community-review",
            ['decision' => 'approve'])->assertOk();
        $this->actingAs($street)->postJson("/api/applications/{$firstId}/street-review",
            ['decision' => 'approve'])->assertOk();

        $this->assertSame(1, Payment::where('application_id', $firstId)->count());
        $this->assertSame(1, Payment::count());
    }

    public function test_不能并入其他批次或其他住址的申请(): void
    {
        $b1 = $this->batch(['name' => '第一批']);
        $b2 = $this->batch(['name' => '第二批', 'started_at' => '2026-02-01 08:00:00']);
        $a = $this->resident();

        $firstId = $this->actingAs($a)->postJson('/api/applications', $this->applyPayload($b1->id))
            ->json('application_id');

        // 尝试在第二批并入第一批的申请 -> 拒绝
        $this->actingAs($a)->postJson('/api/applications', $this->applyPayload($b2->id, [
            'merge_application_id' => $firstId,
            'address' => '其他地址9号',
            'members' => [['name' => '张三', 'id_number' => 'ID-001']],
        ]))->assertStatus(422);
    }
}
