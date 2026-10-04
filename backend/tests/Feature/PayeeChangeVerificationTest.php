<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\PayeeChangeRequest;

/**
 * 场景五：申请冻结（或已核准）后变更收款人，必须街道重新核验后才生效、才能发放。
 */
class PayeeChangeVerificationTest extends SubsidyTestCase
{
    private function prepareApproved(): array
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $community = $this->community();
        $street = $this->street();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');
        $this->submitAllDocs(Application::find($appId), $resident);
        $this->actingAs($community)->postJson("/api/applications/{$appId}/community-review",
            ['decision' => 'approve'])->assertOk();
        $this->actingAs($street)->postJson("/api/applications/{$appId}/street-review",
            ['decision' => 'approve'])->assertOk();

        return [$appId, $resident, $street];
    }

    public function test_核准后变更收款人进入待核验且旧收款人保持不变(): void
    {
        [$appId, $resident, $street] = $this->prepareApproved();

        $this->actingAs($resident)->postJson("/api/applications/{$appId}/payee-change", [
            'payee_name' => '张三代收',
            'payee_account' => '6222-9999',
            'payee_bank' => '建行',
        ])->assertCreated();

        $app = Application::find($appId);
        $this->assertFalse($app->payee_verified, '新收款人未核验前 payee_verified=false');
        $this->assertSame('张三', $app->payee_name, '旧收款人保持不变');
        $this->assertSame('6222-0001', $app->payee_account);
        $this->assertSame('pending', PayeeChangeRequest::firstOrFail()->status);

        // 未核验不能发起打款
        $payment = \App\Models\Payment::where('application_id', $appId)->firstOrFail();
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/payout", [])
            ->assertStatus(422);
    }

    public function test_街道核验通过后收款人才变更且可发放(): void
    {
        [$appId, $resident, $street] = $this->prepareApproved();

        $changeId = $this->actingAs($resident)->postJson("/api/applications/{$appId}/payee-change", [
            'payee_name' => '张三代收',
            'payee_account' => '6222-9999',
        ])->json('data.id');

        $this->actingAs($street)->postJson("/api/payee-changes/{$changeId}/verify", [
            'pass' => true,
        ])->assertOk();

        $app = Application::find($appId);
        $this->assertTrue($app->payee_verified);
        $this->assertSame('张三代收', $app->payee_name);
        $this->assertSame('6222-9999', $app->payee_account);

        // 核验通过可以打款并到账
        $payment = \App\Models\Payment::where('application_id', $appId)->firstOrFail();
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/payout", [])
            ->assertOk();
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [])
            ->assertCreated();
        $this->assertSame('paid', Application::find($appId)->status);
    }

    public function test_核验驳回维持原收款人且恢复可发放状态(): void
    {
        [$appId, $resident, $street] = $this->prepareApproved();

        $changeId = $this->actingAs($resident)->postJson("/api/applications/{$appId}/payee-change", [
            'payee_name' => '陌生人',
            'payee_account' => '0000',
        ])->json('data.id');

        $this->actingAs($street)->postJson("/api/payee-changes/{$changeId}/verify", [
            'pass' => false, 'note' => '无法核实',
        ])->assertOk();

        $app = Application::find($appId);
        $this->assertSame('张三', $app->payee_name);
        $this->assertTrue($app->payee_verified, '驳回后原收款人仍视为已核验');
    }

    public function test_冻结状态下变更收款人同样需要重新核验(): void
    {
        $batch = $this->batch();
        $resident = $this->resident();
        $street = $this->street();

        $appId = $this->actingAs($resident)->postJson('/api/applications',
            $this->applyPayload($batch->id))->json('application_id');
        $this->actingAs($street)->postJson("/api/applications/{$appId}/freeze",
            ['frozen' => true])->assertOk();

        $this->actingAs($resident)->postJson("/api/applications/{$appId}/payee-change", [
            'payee_name' => '新收款人', 'payee_account' => '8888',
        ])->assertCreated();

        $this->assertSame('张三', Application::find($appId)->payee_name);
        $this->assertFalse(Application::find($appId)->payee_verified);
        $this->assertSame('pending', PayeeChangeRequest::firstOrFail()->status);
    }
}
