<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Payment;
use App\Models\PaymentReceipt;

/**
 * 场景六：支付回执重复送达只记一次到账，不能重复发放补贴。
 */
class DuplicateReceiptTest extends SubsidyTestCase
{
    private function preparePayout(): array
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

        $payment = Payment::where('application_id', $appId)->firstOrFail();
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/payout", [])
            ->assertOk();

        return [$payment->fresh(), $street, $appId];
    }

    public function test_同一回执号重复送达只记一次到账(): void
    {
        [$payment, $street, $appId] = $this->preparePayout();

        // 第一张成功回执
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [
            'receipt_no' => 'RCPT-SAME-001',
        ])->assertCreated()->assertJson(['duplicated' => false]);

        // 相同回执号重复送达两次
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [
            'receipt_no' => 'RCPT-SAME-001',
        ])->assertOk()->assertJson(['duplicated' => true]);

        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [
            'receipt_no' => 'RCPT-SAME-001',
        ])->assertOk()->assertJson(['duplicated' => true]);

        // 数据库：一笔 payment、一条 receipt、到账一次
        $this->assertSame(1, Payment::count());
        $this->assertSame('paid', Payment::first()->status);
        $this->assertSame(1, PaymentReceipt::where('receipt_no', 'RCPT-SAME-001')->count());
        $this->assertSame(2, PaymentReceipt::first()->redelivery_count);
        $this->assertSame('paid', Application::find($appId)->status);

        // 到账短信只发一条（受理短信等其他 tag 不受影响）
        $this->assertSame(1,
            \App\Models\SmsMessage::where('tag', 'paid')->count());
    }

    public function test_银行回调接口的重复回执同样幂等(): void
    {
        [$payment, $street, $appId] = $this->preparePayout();

        $payload = [
            'receipt_no' => 'RCPT-BANK-777',
            'transfer_ref' => $payment->transfer_ref,
            'outcome' => 'success',
        ];
        // 回调接口无鉴权（模拟银行服务器）
        $this->postJson('/api/bank/receipts', $payload)->assertCreated();
        $this->postJson('/api/bank/receipts', $payload)->assertOk()
            ->assertJson(['duplicated' => true]);
        $this->postJson('/api/bank/receipts', $payload)->assertOk()
            ->assertJson(['duplicated' => true]);

        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame(2, PaymentReceipt::first()->redelivery_count);
    }

    public function test_已到账的申请不能重复发起发放(): void
    {
        [$payment, $street, $appId] = $this->preparePayout();

        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [])
            ->assertCreated();

        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/payout", [])
            ->assertStatus(422);
    }

    public function test_另一张成功回执晚到也不会二次发钱(): void
    {
        [$payment, $street, $appId] = $this->preparePayout();

        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [
            'receipt_no' => 'RCPT-1',
        ])->assertCreated();

        // 不同回执号但该笔已 paid：留存备查，不重复到账
        $this->actingAs($street)->postJson("/api/payments/{$payment->id}/simulate-receipt", [
            'receipt_no' => 'RCPT-2',
        ])->assertCreated();

        $this->assertSame(2, PaymentReceipt::count());
        $this->assertSame(1, Payment::count());
        $this->assertNotNull(Payment::first()->paid_at);
        $this->assertSame(1, \App\Models\SmsMessage::where('tag', 'paid')->count());

        $events = Application::find($appId)->events()->pluck('type')->all();
        $this->assertContains('receipt_duplicate', $events);
    }
}
