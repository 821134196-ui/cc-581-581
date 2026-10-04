# 暖冬 · 社区低温天气取暖补贴申领与核验系统

按**低温预警批次**受理居民取暖补贴申请：居民线上提交 → 社区初审 → 街道复核 → 形成发放清单 → 模拟支付到账。
后端 PHP/Laravel 12 + Eloquent，前端 React 18 + Vite + Tailwind，SQLite 保存**资格规则快照**与发放批次，短信/支付服务本地模拟。

## 一键启动

```bash
bash bin/start.sh
```

首次运行会自动迁移数据库、填充演示数据、构建前端；随后同时启动：

- **Web 与 API**：http://127.0.0.1:8000
- **模拟短信/支付 worker**（`php artisan sim:worker`）：自动发送通知短信、对发起的支付自动回执到账

> 无全局 PHP/Composer 的 Linux 机器（如本机无 root 的 Debian 环境）可先执行 `bash bin/bootstrap-php.sh`，
> 脚本会把 PHP 8.2（含 pdo_sqlite/mbstring/dom/curl）与 Composer 引导到 `~/.local`，无需 sudo。

演示账号（密码均为 `password`）：

| 角色 | 账号 |
| --- | --- |
| 居民（户主） | `zhang@demo.local` |
| 居民（配偶，演示一户多人） | `zhang-spouse@demo.local` |
| 另一户居民 | `liu@demo.local` |
| 社区初审员 | `community@demo.local` |
| 街道复核员（甲） | `street@demo.local` |
| 街道复核员（乙，演示复核换人） | `street2@demo.local` |

## 业务规则如何落地

| 需求 | 实现方式 |
| --- | --- |
| 按低温预警批次受理 | `batches` 批次 + `eligibility_rules` 规则；`apply_deadline` 正常受理、`late_apply_deadline` 预警后补报 |
| **页面保存本批资格规则与申请时资料** | 提交申请时把规则写入 `applications.rule_snapshot`、资料写入 `payload_snapshot`；审核一律读快照 |
| 规则冻结，补报不换批次 | 规则冻结后不可修改；预警结束后的补报仍挂原批次（`is_late=true`），按 `rule_snapshot` 判断 |
| **一户多名成员重复申请 → 合并** | 以「标准化住址 + 户主身份证」识别同一户；重复件记为 `merged` 影子申请并并入主申请，全流程只发一户一笔，短信与时间线告知 |
| **材料过期提示补充** | 材料到期日 = 签发日 + 规则 `doc_valid_days`；初审/复核当天判定，过期自动标记并退回补件；居民端实时显示缺件/过期清单 |
| **补件沿原申请跟进** | 补件只新增 `documents` 与 `follow_ups(supplement)`，申请编号、批次、规则快照不变，回到社区初审 |
| **申诉沿原申请跟进** | `follow_ups(appeal)` 挂原申请；成立则回到对应环节重审，不另立新批次 |
| **变更收款人 → 冻结 → 重新核验** | 进入审核后改收款人，申请与发放记录同时冻结（`frozen`/`reverify_required`），由**另一名**街道人员重新核验后解冻 |
| **初审员不得复核同一份申请** | `ReviewService` 强校验初审通过人 ≠ 复核人（`segregation_conflict`），另有角色层（community/street）隔离 |
| **支付回执重复只记一次到账** | 回执以 `receipt_no` 全局唯一幂等 + 支付单状态双保险；重复回执只累加 `receipt_count` 留痕，不二次记账、不重复发钱 |
| 居民只能看本户记录 | 接口按 `applicant_id / household_id` 鉴权，跨户返回 403 |
| 社区初审 → 街道复核 → 发放清单 | 复核通过自动生成 `disbursements`（一批次一申请一行） |

## 关键流程

```
居民提交（冻结规则/资料快照）
   │  同户重复 ──► 合并影子申请 + 短信告知（不另受理）
   ▼
社区初审 ──缺件/过期──► 居民补件（沿原申请）──┐
   │通过                                   │回到初审
   ▼                                      │
街道复核（禁与初审同人）──不通过──► 申诉（沿原申请）──成立回到重审
   │通过
   ▼
发放清单 ──发起支付(sim)──► 支付中 ──回执(幂等)──► 到账
   ▲                              │
   └── 改收款人 → 冻结 → 街道重新核验（另一人）──通过──┘
```

## HTTP API（前缀 `/api`，Bearer Token 认证）

- 居民：`POST /auth/{register,login}`、`GET /batches`、`GET|POST /my/applications`、
  `POST /my/applications/{id}/{supplement,appeal,payee}`、`GET /my/applications/{id}/timeline`
- 工作台：`GET /admin/applications`、`POST /admin/applications/{id}/community-review|street-review|reverify`、
  `POST /admin/applications/{id}/appeals/{followUpId}`、批次规则 `admin/batches...`、
  发放 `GET /admin/disbursements`、`POST /admin/disbursements/issue`
- 模拟通道：`POST /sim/payment/receipt`（可重复发同一 `receipt_no` 验证幂等）、`GET /sim/sms`（短信收件箱）

## 常用命令

```bash
# 测试（PHPUnit，SQLite 内存库，共 13 个测试 57 项断言）
php artisan test

# HTTP 全链路冒烟（需先 bin/start.sh；覆盖合并/过期/补件/申诉/冻结重核/重复回执/越权）
python3 scripts/smoke_e2e.py

# 手工模拟支付回执（同号发两次即可验证重复只记一次）
php artisan sim:receipt --receipt-no=RCP-DEMO-1
php artisan sim:receipt --receipt-no=RCP-DEMO-1

# 重置演示数据
php artisan migrate:fresh --seed --seeder=DemoSeeder --force
```

## 目录要点

- `app/Services/`：`ApplicationService`（提交/合并/补件/申诉/收款人冻结）、`ReviewService`（两级审核/重核/申诉处理）、
  `DisbursementService` + `MockPaymentService`（发放与回执幂等）、`EligibilityChecker`（按快照判资格/过期）、`MockSmsService`
- `app/Models/`：Batch、EligibilityRule、Application、Document、Review、FollowUp、Disbursement、PaymentEvent、StatusLog、SmsMessage
- `database/migrations/`：13 个迁移；规则与申请资料均以 JSON 快照落 SQLite
- `resources/js/pages/`：居民端 `resident/`、社区/街道工作台 `admin/`
