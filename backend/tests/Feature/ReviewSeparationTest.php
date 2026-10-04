<?php

namespace Tests\Feature;

use App\Models\Application;

/**
 * 场景四：初审与复核岗位分离——做过初审的人不得复核同一份申请。
 */
class ReviewSeparationTest extends SubsidyTestCase
{
    public function test_同一账号先初审后复核同一份申请被拒绝(): void
    {
        // 该账号既是社区初审员又是街道复核员（现实中不应出现，但用它直接验证后端强校验）
        $dual = \App\Models\User::create([
            'name' => '双角色账号', 'phone' => '13600000001', 'role' => 'community',
            'password' => bcrypt('password'),
        ]);
        $realStreet = $this->street('13700000002');

        $batch = $this->batch();
        $resident = $this->resident();
        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');
        $this->submitAllDocs(Application::find($appId), $resident);

        // 该账号完成初审
        $this->actingAs($dual)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertOk();

        // 即使把他改成 street 角色，后端按 reviews 记录也必须拦截
        $dual->update(['role' => 'street']);
        $this->actingAs($dual)->postJson("/api/applications/{$appId}/street-review",
            ['decision' => 'approve'])->assertStatus(422);

        $this->assertSame('pending_street', Application::find($appId)->status);

        // 换另一位街道复核员可以通过
        $this->actingAs($realStreet)->postJson("/api/applications/{$appId}/street-review",
            ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', Application::find($appId)->status);
    }

    public function test_社区账号不能调用复核接口反之亦然(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $community = $this->community();
        $street = $this->street();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        // 街道不能做初审
        $this->actingAs($street)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertStatus(403);
        // 社区不能做复核
        $this->actingAs($community)->postJson("/api/applications/{$appId}/street-review",
            ['decision' => 'approve'])->assertStatus(403);
    }

    public function test_冻结期间不能审核或发放(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $community = $this->community();
        $street = $this->street();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');

        // 街道冻结
        $this->actingAs($street)->postJson("/api/applications/{$appId}/freeze",
            ['frozen' => true, 'reason' => '收款人有疑'])->assertOk();

        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertStatus(423);

        // 解冻后恢复
        $this->actingAs($street)->postJson("/api/applications/{$appId}/freeze",
            ['frozen' => false])->assertOk();
        $this->submitAllDocs(Application::find($appId), $resident);
        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertOk();
        $this->actingAs($street)->postJson("/api/applications/{$appId}/street-review",
            ['decision' => 'approve'])->assertOk();

        // 再冻结：清单中该笔不能打款
        $this->actingAs($street)->postJson("/api/applications/{$appId}/freeze",
            ['frozen' => true])->assertOk();
        $payment = \App\Models\Payment::where('application_id', $appId)->firstOrFail();
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/payout", [])
            ->assertStatus(423);
    }
}
