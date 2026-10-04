<?php

namespace Tests\Feature;

use App\Models\AlertBatch;
use App\Models\Application;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SubsidyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role, string $phone): User
    {
        return User::create([
            'name' => $role.'-'.$phone,
            'phone' => $phone,
            'role' => $role,
            'password' => Hash::make('password'),
        ]);
    }

    protected function resident(string $phone = '13800000001', string $name = '居民甲'): User
    {
        return User::create([
            'name' => $name,
            'phone' => $phone,
            'role' => 'resident',
            'password' => Hash::make('password'),
        ]);
    }

    protected function community(string $phone = '13900000001'): User
    {
        return $this->makeUser('community', $phone);
    }

    protected function street(string $phone = '13700000001'): User
    {
        return $this->makeUser('street', $phone);
    }

    protected function batch(array $attrs = []): AlertBatch
    {
        return AlertBatch::create(array_merge([
            'name' => '寒潮橙色预警',
            'level' => 'orange',
            'started_at' => Carbon::parse('2026-01-05 08:00:00'),
            'ended_at' => null,
            'is_accepting' => true,
            'late_filing_deadline' => Carbon::parse('2026-01-20')->toDateString(),
            'subsidy_amount' => 60000,
            'rules' => [
                'version' => 'v1',
                'eligible_heating_methods' => ['coal', 'electric'],
                'required_documents' => [
                    ['type' => 'id_card', 'label' => '身份证'],
                    ['type' => 'heating_bill', 'label' => '取暖缴费凭证'],
                ],
            ],
        ], $attrs));
    }

    protected function applyPayload(int $batchId, array $overrides = []): array
    {
        return array_merge([
            'alert_batch_id' => $batchId,
            'address' => '幸福里3栋201',
            'heating_method' => 'coal',
            'payee_name' => '张三',
            'payee_account' => '6222-0001',
            'payee_bank' => '工行',
            'members' => [
                ['name' => '张三', 'id_number' => 'ID-001', 'phone' => '13800000001'],
            ],
        ], $overrides);
    }

    protected function submitAllDocs(Application $app, User $resident, array $expiry = []): void
    {
        foreach ($app->documents as $doc) {
            $this->actingAs($resident)->postJson("/api/applications/{$app->id}/documents", [
                'doc_type' => $doc->doc_type,
                'expires_at' => $expiry[$doc->doc_type] ?? null,
            ])->assertOk();
        }
    }
}
