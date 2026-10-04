<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\EligibilityRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $street = User::firstOrCreate(
            ['email' => 'street@demo.local'],
            [
                'name' => '王街道', 'role' => User::ROLE_STREET,
                'employee_no' => 'ST-001', 'phone' => '13800000001',
                'password' => Hash::make('password'),
            ],
        );
        $community = User::firstOrCreate(
            ['email' => 'community@demo.local'],
            [
                'name' => '李社区', 'role' => User::ROLE_COMMUNITY,
                'employee_no' => 'CM-001', 'phone' => '13800000002',
                'password' => Hash::make('password'),
            ],
        );
        // 第二个街道账号：用于演示“初审/复核同人禁止”（若用社区账号充当复核会被拒）
        User::firstOrCreate(
            ['email' => 'street2@demo.local'],
            [
                'name' => '赵街道', 'role' => User::ROLE_STREET,
                'employee_no' => 'ST-002', 'phone' => '13800000003',
                'password' => Hash::make('password'),
            ],
        );

        User::firstOrCreate(
            ['email' => 'zhang@demo.local'],
            [
                'name' => '张居民', 'role' => User::ROLE_RESIDENT,
                'phone' => '13900000001', 'idcard' => '11010119900101001X',
                'password' => Hash::make('password'),
            ],
        );
        User::firstOrCreate(
            ['email' => 'zhang-spouse@demo.local'],
            [
                'name' => '张居民配偶', 'role' => User::ROLE_RESIDENT,
                'phone' => '13900000002', 'idcard' => '11010119920303002X',
                'password' => Hash::make('password'),
            ],
        );
        User::firstOrCreate(
            ['email' => 'liu@demo.local'],
            [
                'name' => '刘居民', 'role' => User::ROLE_RESIDENT,
                'phone' => '13900000004', 'idcard' => '11010119880808003X',
                'password' => Hash::make('password'),
            ],
        );

        // 本批低温预警：规则冻结；预警已结束但补报窗口仍开放（便于演示补报按本批规则）
        $today = Carbon::today();
        $batch = Batch::firstOrCreate(
            ['code' => '2026-W01'],
            [
                'title' => '2026年第一轮低温橙色预警取暖补贴',
                'warning_start' => $today->copy()->subDays(20),
                'warning_end' => $today->copy()->subDays(3),
                'apply_deadline' => $today->copy()->subDays(2),
                'late_apply_deadline' => $today->copy()->addDays(10),
                'status' => 'open',
            ],
        );

        EligibilityRule::firstOrCreate(
            ['batch_id' => $batch->id],
            [
                'low_temp_threshold' => -5,
                'allowed_heating_methods' => ['集中供暖', '自采暖', '电暖'],
                'doc_valid_days' => 90,
                'required_documents' => ['idcard', 'residence', 'bankcard'],
                'subsidy_amount' => 300.00,
                'subsidy_unit' => '户',
                'residency_requirement' => '本社区户籍或连续居住满6个月',
                'version' => 1,
            ],
        );

        if (! $batch->rules_frozen) {
            $batch->update([
                'rules_frozen' => true,
                'rules_frozen_at' => Carbon::now(),
                'rules_frozen_by' => $street->id,
            ]);
        }
    }
}
