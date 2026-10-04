<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;

/**
 * 场景二：资格规则快照 + 预警结束后的补报按本批规则，不切换批次。
 */
class RuleSnapshotAndLateFilingTest extends SubsidyTestCase
{
    public function test_申请时保存规则快照之后批次改规则不影响本申请(): void
    {
        Carbon::setTestNow('2026-01-06 10:00:00');
        $batch = $this->batch();
        $resident = $this->resident();

        $appId = $this->actingAs($resident)
            ->postJson('/api/applications', $this->applyPayload($batch->id, ['heating_method' => 'coal']))
            ->json('application_id');

        // 申请后批次规则变化：coal 不再补贴、新增材料要求
        $batch->update(['rules' => [
            'version' => 'v2',
            'eligible_heating_methods' => ['electric'],
            'required_documents' => [
                ['type' => 'id_card', 'label' => '身份证'],
                ['type' => 'heating_bill', 'label' => '取暖缴费凭证'],
                ['type' => 'new_doc', 'label' => '新增的材料'],
            ],
        ]]);

        $app = \App\Models\Application::find($appId);
        $this->assertSame('v1', $app->rule_snapshot['version']);
        $this->assertContains('coal', $app->rule_snapshot['eligible_heating_methods']);

        // 按快照：coal 仍合规，材料仍是两份，不出现新增材料
        $this->submitAllDocs($app->fresh(), $resident);
        $detail = $this->actingAs($resident)->getJson("/api/applications/{$appId}")->json('data');
        $this->assertTrue($detail['eligibility']['eligible']);
        $this->assertCount(2, $detail['documents']);
        Carbon::setTestNow();
    }

    public function test_预警结束后补报标记late且仍按本批规则(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00'); // 在补报期内（截止 1-20）
        $batch = $this->batch([
            'ended_at' => Carbon::parse('2026-01-10 20:00:00'),
        ]);
        $resident = $this->resident();

        $res = $this->actingAs($resident)
            ->postJson('/api/applications', $this->applyPayload($batch->id))
            ->assertCreated();
        $appId = $res->json('application_id');

        $app = \App\Models\Application::find($appId);
        $this->assertTrue($app->is_late);
        $this->assertSame($batch->rules['version'], $app->rule_snapshot['version']);

        $detail = $this->actingAs($resident)->getJson("/api/applications/{$appId}")->json('data');
        $this->assertTrue($detail['is_late']);
        Carbon::setTestNow();
    }

    public function test_超过补报截止日期不能申请(): void
    {
        Carbon::setTestNow('2026-01-21 10:00:00'); // 已过 1-20 补报期
        $batch = $this->batch(['ended_at' => Carbon::parse('2026-01-10 20:00:00')]);
        $resident = $this->resident();

        $this->actingAs($resident)
            ->postJson('/api/applications', $this->applyPayload($batch->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alert_batch_id']);
        Carbon::setTestNow();
    }

    public function test_服务端不会自动把申请切到其他批次(): void
    {
        // 给第一批补报时，即使系统里有进行中的第二批，申请仍挂第一批
        Carbon::setTestNow('2026-01-15 10:00:00');
        $ended = $this->batch(['name' => '第一批', 'ended_at' => '2026-01-10 20:00:00']);
        $active = $this->batch(['name' => '第二批', 'started_at' => '2026-01-12 08:00:00',
            'ended_at' => null, 'late_filing_deadline' => null]);
        $resident = $this->resident();

        $appId = $this->actingAs($resident)
            ->postJson('/api/applications', $this->applyPayload($ended->id))
            ->json('application_id');

        $this->assertSame($ended->id, \App\Models\Application::find($appId)->alert_batch_id);
        $this->assertNotSame($active->id, \App\Models\Application::find($appId)->alert_batch_id);
        Carbon::setTestNow();
    }

    public function test_供暖方式不在本批范围则资格不通过(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();

        $appId = $this->actingAs($resident)
            ->postJson('/api/applications', $this->applyPayload($batch->id, ['heating_method' => 'firewood']))
            ->json('application_id');

        $detail = $this->actingAs($resident)->getJson("/api/applications/{$appId}")->json('data');
        $this->assertFalse($detail['eligibility']['eligible']);
        $this->assertFalse($detail['eligibility']['heating_ok']);

        // 社区初审通过会被拦下
        $community = $this->community();
        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame('submitted', \App\Models\Application::find($appId)->status);
    }
}
