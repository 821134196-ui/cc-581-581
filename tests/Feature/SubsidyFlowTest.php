<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Batch;
use App\Models\Disbursement;
use App\Models\EligibilityRule;
use App\Models\FollowUp;
use App\Models\PaymentEvent;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use App\Exceptions\DomainException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsidyFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $street;
    private User $street2;
    private User $community;
    private User $community2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        $this->street = User::create(['name' => '王街道', 'email' => 's1@t.co', 'password' => bcrypt('x'), 'role' => User::ROLE_STREET, 'employee_no' => 'S1']);
        $this->street2 = User::create(['name' => '赵街道', 'email' => 's2@t.co', 'password' => bcrypt('x'), 'role' => User::ROLE_STREET, 'employee_no' => 'S2']);
        $this->community = User::create(['name' => '李社区', 'email' => 'c1@t.co', 'password' => bcrypt('x'), 'role' => User::ROLE_COMMUNITY, 'employee_no' => 'C1']);
        $this->community2 = User::create(['name' => '孙社区', 'email' => 'c2@t.co', 'password' => bcrypt('x'), 'role' => User::ROLE_COMMUNITY, 'employee_no' => 'C2']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeBatch(array $overrides = []): Batch
    {
        $batch = Batch::create(array_merge([
            'code' => 'W01',
            'title' => '第一轮低温预警',
            'warning_start' => '2026-01-05',
            'warning_end' => '2026-01-10',
            'apply_deadline' => '2026-01-15',
            'late_apply_deadline' => '2026-01-25',
            'status' => 'open',
        ], $overrides));

        EligibilityRule::create([
            'batch_id' => $batch->id,
            'low_temp_threshold' => -5,
            'allowed_heating_methods' => ['集中供暖', '自采暖', '电暖'],
            'doc_valid_days' => 90,
            'required_documents' => ['idcard', 'residence', 'bankcard'],
            'subsidy_amount' => 300,
            'residency_requirement' => '本社区户籍或连续居住满6个月',
        ]);

        return $batch;
    }

    private function resident(string $email = 'r1@t.co', string $name = '张居民'): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('x'),
            'role' => User::ROLE_RESIDENT, 'phone' => '13900000000',
            'idcard' => '11010119900101001X',
        ]);
    }

    private function submitPayload(int $batchId, array $docOverrides = [], array $extra = []): array
    {
        $docs = [
            ['doc_type' => 'idcard', 'issued_on' => '2026-01-10'],
            ['doc_type' => 'residence', 'issued_on' => '2026-01-10'],
            ['doc_type' => 'bankcard', 'issued_on' => '2026-01-10'],
        ];
        foreach ($docOverrides as $i => $ov) {
            $docs[$i] = array_merge($docs[$i], $ov);
        }

        return array_merge([
            'batch_id' => $batchId,
            'address' => '幸福里小区 1号楼 101室',
            'household_head_name' => '张居民',
            'household_head_idcard' => '11010119900101001X',
            'heating_method' => '自采暖',
            'residency_met' => true,
            'payee' => ['name' => '张居民', 'account' => '6222000000000001', 'bank' => '模拟银行'],
            'documents' => $docs,
        ], $extra);
    }

    private function approve(Application $app): Application
    {
        app(ReviewService::class)->communityReview($app, $this->community, 'pass', '材料齐全');
        app(ReviewService::class)->streetReview($app->fresh(), $this->street, 'pass', '复核无误');
        return $app->fresh();
    }

    // ---------- 场景一：一户多名成员重复申请自动合并 ----------

    public function test_duplicate_application_from_same_household_is_merged(): void
    {
        $batch = $this->makeBatch();
        $r1 = $this->resident('r1@t.co', '张居民');
        $r2 = User::create([
            'name' => '张配偶', 'email' => 'r2@t.co', 'password' => bcrypt('x'),
            'role' => User::ROLE_RESIDENT, 'phone' => '13900000002',
            'idcard' => '11010119920303002X',
        ]);

        $svc = app(\App\Services\ApplicationService::class);
        $first = $svc->submit($r1, $batch->id, $this->submitPayload($batch->id));
        $second = $svc->submit($r2, $batch->id, $this->submitPayload($batch->id, [], ['address' => '幸福里小区1号楼101室']));

        $this->assertFalse($first['merged']);
        $this->assertTrue($second['merged']);
        $this->assertSame($first['application']->id, $second['application']->id);

        // 重复件作为影子记录存在且标记 merged
        $shadow = Application::where('merged', true)->where('applicant_id', $r2->id)->first();
        $this->assertNotNull($shadow);
        $this->assertSame($first['application']->id, $shadow->merged_into_id);

        // 全流程走完后只有一笔发放
        $this->approve($first['application']);
        $this->assertSame(1, Disbursement::where('household_id', $first['application']->household_id)->count());

        // 时间线里有合并提示，第二位居民收到合并短信
        $this->assertDatabaseHas('status_logs', [
            'application_id' => $first['application']->id,
            'event' => 'household_duplicate_merged',
        ]);
        $this->assertDatabaseHas('sms_messages', [
            'phone' => '13900000002', 'template' => 'application_merged',
        ]);
    }

    // ---------- 场景二：材料过期提示补充 ----------

    public function test_expired_documents_trigger_supplement_request(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);

        // 居住证明签发于 200 天前，超过 90 天有效期
        $res = $svc->submit($r, $batch->id, $this->submitPayload($batch->id, [
            1 => ['issued_on' => '2025-07-01'],
        ]));
        $app = $res['application'];

        app(ReviewService::class)->communityReview($app, $this->community, 'pass');
        $app->refresh();

        $this->assertSame(Application::STATUS_SUPPLEMENT_REQUESTED, $app->status);
        $this->assertSame('expired', $app->documents()->where('doc_type', 'residence')->first()->status);

        $log = $app->statusLogs()->where('event', 'supplement_requested')->latest('id')->first();
        $this->assertStringContainsString('居住证明', $log->message);
    }

    // ---------- 场景三：补件沿原申请跟进 ----------

    public function test_supplement_follows_original_application(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);

        $res = $svc->submit($r, $batch->id, $this->submitPayload($batch->id, [
            1 => ['issued_on' => '2025-07-01'],
        ]));
        $app = $res['application'];
        $originalNo = $app->apply_no;
        $originalBatch = $app->batch_id;

        app(ReviewService::class)->communityReview($app, $this->community, 'pass');

        $svc->supplement($app->fresh(), $r, [
            ['doc_type' => 'residence', 'issued_on' => '2026-01-18'],
        ], '补交新的居住证明');

        $app->refresh();
        $this->assertSame(Application::STATUS_SUBMITTED, $app->status);
        $this->assertSame($originalNo, $app->apply_no, '补件不得产生新申请编号');
        $this->assertSame($originalBatch, $app->batch_id, '补件不得更换批次');
        $this->assertDatabaseHas('follow_ups', [
            'application_id' => $app->id, 'type' => 'supplement', 'user_id' => $r->id,
        ]);

        // 补件后审核可通过、发放
        $this->approve($app);
        $this->assertSame(Application::STATUS_APPROVED, $app->fresh()->status);
    }

    // ---------- 场景四：申诉沿原申请跟进 ----------

    public function test_appeal_follows_original_application_and_can_be_accepted(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $res = $svc->submit($r, $batch->id, $this->submitPayload($batch->id));
        $app = $res['application'];
        $no = $app->apply_no;

        app(ReviewService::class)->communityReview($app, $this->community, 'reject', '供暖方式存疑');
        $this->assertSame(Application::STATUS_COMMUNITY_REJECTED, $app->fresh()->status);

        $appeal = $svc->appeal($app->fresh(), $r, '我家确为自采暖，可提供缴费凭证');
        $this->assertSame(Application::STATUS_APPEALING, $app->fresh()->status);

        app(ReviewService::class)->handleAppeal($app->fresh(), $appeal, $this->community2, true, '情况属实，重新审核');
        $app->refresh();
        $this->assertSame(Application::STATUS_SUBMITTED, $app->status);
        $this->assertSame($no, $app->apply_no, '申诉沿原申请，不换新申请');
        $this->assertSame(FollowUp::STATUS_ACCEPTED, $appeal->fresh()->status);
    }

    // ---------- 场景五：支付回执重复送达只到账一次 ----------

    public function test_duplicate_payment_receipt_records_only_one_disbursement(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $app = $svc->submit($r, $batch->id, $this->submitPayload($batch->id))['application'];
        $this->approve($app);

        $dService = app(\App\Services\DisbursementService::class);
        $nos = $dService->issuePayments($batch->id);
        $this->assertCount(1, $nos);
        $paymentNo = $nos[0];

        $first = $dService->handleReceipt($paymentNo, 'RCP-DUP-001');
        $second = $dService->handleReceipt($paymentNo, 'RCP-DUP-001');
        $third = $dService->handleReceipt($paymentNo, 'RCP-DUP-001');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertTrue($third['duplicate']);

        // 只有一条发放记录、一次到账
        $disbursement = Disbursement::where('application_id', $app->id)->first();
        $this->assertSame(Disbursement::STATUS_PAID, $disbursement->status);
        $this->assertSame('RCP-DUP-001', $disbursement->receipt_no);
        $this->assertSame(3, $disbursement->receipt_count, '三次送达都留痕');
        $this->assertSame(1, Disbursement::where('application_id', $app->id)->count());
        $this->assertSame(1, PaymentEvent::where('result', PaymentEvent::RESULT_RECORDED)->count());
        $this->assertSame(2, PaymentEvent::where('result', PaymentEvent::RESULT_DUPLICATE)->count());
        $this->assertSame(Application::STATUS_DISBURSED, $app->fresh()->status);
    }

    // ---------- 场景六：申请冻结后变更收款人要重新核验 ----------

    public function test_changing_payee_after_review_freezes_and_requires_reverify(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $app = $svc->submit($r, $batch->id, $this->submitPayload($batch->id))['application'];

        app(ReviewService::class)->communityReview($app, $this->community, 'pass');
        app(ReviewService::class)->streetReview($app->fresh(), $this->street, 'pass');
        $app->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $app->status);
        $disbursement = Disbursement::where('application_id', $app->id)->first();

        // 居民变更收款人 → 申请与发放同时冻结
        $svc->changePayee($app, $r, ['name' => '张配偶', 'account' => '6222999999999999', 'bank' => '模拟银行']);
        $app->refresh();
        $disbursement->refresh();
        $this->assertSame(Application::STATUS_FROZEN, $app->status);
        $this->assertTrue($app->reverify_required);
        $this->assertSame(Application::STATUS_APPROVED, $app->status_before_freeze);
        $this->assertSame(Disbursement::STATUS_FROZEN, $disbursement->status);

        // 冻结期间发起支付不会带出该笔（issue 只取 listed）
        $this->assertSame([], app(\App\Services\DisbursementService::class)->issuePayments($batch->id));

        // 重新核验通过 → 恢复 approved，清单恢复 listed，收款信息已更新
        app(ReviewService::class)->reverifyAfterFreeze($app->fresh(), $this->street2, 'pass', '新人账户核验通过');
        $app->refresh();
        $disbursement->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $app->status);
        $this->assertFalse($app->reverify_required);
        $this->assertSame(Disbursement::STATUS_LISTED, $disbursement->status);
        $this->assertSame('6222999999999999', $disbursement->payee_account);
    }

    // ---------- 场景七：初审人不得复核同一份申请 ----------

    public function test_community_reviewer_cannot_review_same_application_at_street(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $app = $svc->submit($r, $batch->id, $this->submitPayload($batch->id))['application'];

        app(ReviewService::class)->communityReview($app, $this->community, 'pass');

        // 社区账号调复核接口：角色层面直接拒绝
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('无权');
        app(ReviewService::class)->streetReview($app->fresh(), $this->community, 'pass');
    }

    public function test_service_blocks_reviewer_who_was_also_initial_reviewer(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $app = $svc->submit($r, $batch->id, $this->submitPayload($batch->id))['application'];

        // 构造“同一人既是初审人又试图复核”的记录（越过角色层，验证服务层防线）
        Review::create([
            'application_id' => $app->id,
            'level' => Review::LEVEL_COMMUNITY,
            'reviewer_id' => $this->street->id,
            'result' => Review::RESULT_PASS,
        ]);
        $app->update(['status' => Application::STATUS_STREET_PENDING]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('不能再复核同一份申请');
        app(ReviewService::class)->streetReview($app->fresh(), $this->street, 'pass');
    }

    // ---------- 场景八：预警结束后补报按本批规则，不换批次 ----------

    public function test_late_application_after_warning_uses_frozen_batch_rules(): void
    {
        $batch = $this->makeBatch();
        $batch->update([
            'rules_frozen' => true,
            'rules_frozen_at' => Carbon::now(),
            'rules_frozen_by' => $this->street->id,
        ]);
        $rule = EligibilityRule::where('batch_id', $batch->id)->first();

        // 冻结后修改规则被拒绝
        try {
            app(\App\Services\BatchService::class)->configureRule($batch->fresh(), ['subsidy_amount' => 999]);
            $this->fail('冻结后不应允许修改规则');
        } catch (DomainException $e) {
            $this->assertSame('rules_frozen', $e->errorCode);
        }

        // 时间进入“预警结束后的补报窗口”
        Carbon::setTestNow(Carbon::parse('2026-01-18 10:00:00'));
        $r = $this->resident();
        $svc = app(\App\Services\ApplicationService::class);
        $app = $svc->submit($r, $batch->id, $this->submitPayload($batch->id))['application'];

        $this->assertTrue($app->is_late);
        $this->assertSame('W01', $app->batch->code, '补报必须挂在原批次');
        $this->assertSame('300.00', $app->rule_snapshot['subsidy_amount']);
        $this->assertSame(90, $app->rule_snapshot['doc_valid_days']);
    }

    // ---------- 其他：居民只能看本户；缺材料直接判定不合格 ----------

    public function test_resident_cannot_view_other_household_application(): void
    {
        $batch = $this->makeBatch();
        $r1 = $this->resident('r1@t.co');
        $other = $this->resident('r9@t.co', '外人');

        $app = app(\App\Services\ApplicationService::class)
            ->submit($r1, $batch->id, $this->submitPayload($batch->id))['application'];

        $response = $this->actingAsToken($other)->getJson("/api/my/applications/{$app->id}");
        $response->assertForbidden();
    }

    public function test_missing_document_is_reported_to_resident(): void
    {
        $batch = $this->makeBatch();
        $r = $this->resident();
        $payload = $this->submitPayload($batch->id);
        $payload['documents'] = array_slice($payload['documents'], 0, 2); // 缺 bankcard

        $app = app(\App\Services\ApplicationService::class)
            ->submit($r, $batch->id, $payload)['application'];

        $response = $this->actingAsToken($r)->getJson("/api/my/applications/{$app->id}");
        $response->assertOk();
        $json = $response->json();
        $this->assertFalse($json['eligibility']['eligible']);
        $this->assertSame('bankcard', $json['eligibility']['missing_docs'][0]['doc_type']);
    }

    private function actingAsToken(User $user): static
    {
        $plain = \Illuminate\Support\Str::random(40);
        $user->forceFill(['api_token' => hash('sha256', $plain)])->save();
        return $this->withToken($plain);
    }
}
