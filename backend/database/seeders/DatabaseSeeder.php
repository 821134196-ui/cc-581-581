<?php

namespace Database\Seeders;

use App\Models\AlertBatch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            // 居民（一家三口：父母各有账号，模拟一户多人申请）
            ['name' => '张建国', 'phone' => '13800000001', 'role' => 'resident'],
            ['name' => '李秀兰', 'phone' => '13800000002', 'role' => 'resident'],
            ['name' => '王大爷', 'phone' => '13800000003', 'role' => 'resident'],
            // 社区初审员两人
            ['name' => '社区-小陈', 'phone' => '13900000001', 'role' => 'community'],
            ['name' => '社区-小赵', 'phone' => '13900000002', 'role' => 'community'],
            // 街道复核员两人
            ['name' => '街道-老周', 'phone' => '13700000001', 'role' => 'street'],
            ['name' => '街道-老吴', 'phone' => '13700000002', 'role' => 'street'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['phone' => $u['phone']],
                ['name' => $u['name'], 'role' => $u['role'], 'password' => Hash::make('password')],
            );
        }

        $rules = [
            'version' => 'v2026-winter-a',
            'eligible_heating_methods' => ['coal', 'electric', 'gas', 'central_heating_self_paid'],
            'required_documents' => [
                ['type' => 'id_card', 'label' => '居民身份证'],
                ['type' => 'residence_proof', 'label' => '居住证明（户口本/房产证/租房合同）'],
                ['type' => 'low_income_proof', 'label' => '低保证明或困难认定材料'],
                ['type' => 'heating_bill', 'label' => '本供暖季取暖缴费凭证'],
            ],
        ];

        // 进行中的低温预警批次
        AlertBatch::updateOrCreate(
            ['name' => '2026年1月寒潮橙色预警'],
            [
                'level' => 'orange',
                'started_at' => Carbon::parse('2026-01-05 08:00:00'),
                'ended_at' => null,
                'is_accepting' => true,
                'late_filing_deadline' => Carbon::parse('2026-01-20')->toDateString(),
                'subsidy_amount' => 60000, // 600.00 元，单位分
                'rules' => $rules,
            ],
        );

        // 已结束的批次（用于演示补报按本批规则、不得换批）
        AlertBatch::updateOrCreate(
            ['name' => '2025年12月强降温蓝色预警'],
            [
                'level' => 'blue',
                'started_at' => Carbon::parse('2025-12-01 08:00:00'),
                'ended_at' => Carbon::parse('2025-12-10 20:00:00'),
                'is_accepting' => true,
                'late_filing_deadline' => Carbon::parse('2025-12-20')->toDateString(),
                'subsidy_amount' => 30000,
                'rules' => [
                    'version' => 'v2025-winter-c',
                    'eligible_heating_methods' => ['coal', 'electric'],
                    'required_documents' => [
                        ['type' => 'id_card', 'label' => '居民身份证'],
                        ['type' => 'residence_proof', 'label' => '居住证明'],
                        ['type' => 'heating_bill', 'label' => '取暖缴费凭证'],
                    ],
                ],
            ],
        );
    }
}
