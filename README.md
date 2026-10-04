# 社区低温天气取暖补贴申领与核验系统

社区按**低温预警批次**受理居民取暖补贴申请：居民线上填写住址、家庭成员、供暖方式、收款信息并上传材料 →
**社区初审 → 街道复核 → 形成发放清单 → 模拟银行转账 → 回执到账**。居民可随时查看资格判断、缺件/过期提示、
审核进度与到账情况。短信通知与支付服务全部**本地模拟**，不依赖任何外部服务。

- 后端：PHP 8.2+ / Laravel / Eloquent / SQLite
- 前端：React 18 + Vite（构建后由 Laravel 一并托管）
- 数据库：SQLite，保存**批次资格规则快照**、申请资料快照与发放批次

## 一条命令启动

```bash
./dev.sh
```

自动完成：PHP/Composer 依赖检查 → 迁移并填充演示数据 → 构建 React → 启动服务。

启动后访问 **http://127.0.0.1:8000/spa/**

其他命令：

| 命令 | 说明 |
| --- | --- |
| `./dev.sh dev` | 开发模式：Laravel(8000) + Vite 热更新(5173) |
| `./dev.sh build` | 只构建前端到 `backend/public/spa` |
| `./dev.sh reset` | 重建 SQLite 并填充演示数据 |
| `./dev.sh test` | 运行全部 24 个场景测试（PHPUnit） |
| `./dev.sh sweep` | 手动触发一次材料过期扫描（生产由调度器每日 08:00 执行） |

> 无 root 环境：脚本优先使用 `~/.local/bin` 下的静态 PHP（本工作区已安装 PHP 8.4.8 + Composer）。
> 系统自带 PHP 8.2+（需 `pdo_sqlite/mbstring/curl/dom/xml`）时可直接运行。

### 演示账号（密码均为 `password`）

| 角色 | 手机号 |
| --- | --- |
| 居民（张建国） | 13800000001 |
| 居民（李秀兰，同户成员） | 13800000002 |
| 社区初审员-小陈 | 13900000001 |
| 社区初审员-小赵 | 13900000002 |
| 街道复核员-老周 | 13700000001 |
| 街道复核员-老吴 | 13700000002 |

## 业务规则与实现位置

| 规则 | 实现 |
| --- | --- |
| 按低温预警批次受理，**资格规则在申请时整体快照**（`rule_snapshot`），批次后改规则不影响在途申请 | `ApplicationService::apply()` / `eligibility()` |
| 预警结束后的补报标记 `is_late`，在补报截止期内**仍按本批规则**审核，服务端绝不自动切换批次 | `AlertBatch::acceptsLateFiling()` + 申请必须显式带 `alert_batch_id` |
| 同一户多名成员重复申请：先返回 **409 合并提示**，居民确认后并入原申请；补贴按户只发一次（地址做归一化哈希） | `Household` + `DuplicateApplicationException` + 合并事务 |
| 材料缺失/过期明确提示；每日扫描过期材料，在途申请自动退回补件并短信通知；补件沿原申请，不新建、不换批 | `ApplicationService::documentStatus()/submitDocument()/sweepExpiredDocuments()`，命令 `subsidy:sweep-expired` |
| 申诉沿原申请跟进，成立后按原批次重新审核 | `AppealService` |
| **初审与复核岗位分离**：做过该申请初审的人（按 `reviews` 记录判定，即使账号角色变更）不得复核同一份申请 | `ReviewService::streetReview()` |
| 申请**冻结**后暂停审核与发放；冻结/核准后**变更收款人需街道重新核验**，核验前保留旧收款人且禁止打款 | `ReviewService::freeze()/requestPayeeChange()/verifyPayeeChange()` |
| 复核通过自动形成**本批发放清单**（每户一笔，DB 唯一约束兜底） | `PaymentService::createRosterEntry()`，`payments.application_id` unique |
| **支付回执重复送达只记一次到账**：回执号全局唯一 + 行锁 + 乐观计数，重复回执累加 `redelivery_count`，不重复发钱/发短信 | `PaymentService::receiveReceipt()` |
| 居民只能查看本人申请（或本人作为成员挂接的申请） | `ApplicationController::authorizeView()` |
| 短信、支付本地模拟：短信落 `sms_messages`（页面"短信箱"可见）；银行通过 `/api/bank/receipts` 回调或页面按钮模拟回执 | `SmsService` / `MockPaymentGateway` / `PaymentController` |

## 页面导览

- **居民端**：申请补贴（选批次、填住址/成员/供暖/收款）、我的申请（资格、缺件、到账进度）、
  申请详情（补件、申诉、变更收款人、全程时间线）、短信箱。
- **社区端**：初审队列（通过 / 退回补件 / 不通过）、申诉处理、短信箱。
- **街道端**：复核队列（自动标注"本人初审过，不可复核"）、冻结/解冻、收款人变更核验、
  发放清单（发起转账、模拟成功回执、**重复送达回执**验证幂等）、批次发布与结束预警。

## 建议的手工演示路径

1. 用 `13800000001` 申请；换 `13800000002` 在同一住址再申请 → 看到 409 合并提示 → 确认并入。
2. 补交一份过期日期的材料（如有效期选 2020 年）→ 资格判断显示过期；再传有效日期 + 其余材料 → 符合资格。
3. 社区 `13900000001` 初审通过；街道 `13700000001` 复核通过 → 发放清单出现该户。
4. 街道发起转账 → 模拟成功回执 → 居民端看到到账与短信；再点"重复送达回执"→ 提示已忽略，金额只到账一次。
5. 在另一件申请上：街道冻结 → 居民变更收款人 → 街道"待办"里核验通过后才能打款。
6. 批次页"结束预警"后再申请 → 标记"预警后补报（按本批规则）"；过补报截止日则无法申请。

## 测试

```bash
./dev.sh test
```

6 个测试文件、24 个用例覆盖：一户多人申请合并、规则快照与补报不换批、材料过期/补件/申诉、
初审复核同人禁止、冻结与收款人变更重新核验、支付回执重复送达幂等等全部关键场景。

## 目录结构

```
backend/
  app/
    Models/            Household/Member/AlertBatch/Application/Document/Appeal/Review/
                       Payment/PaymentReceipt/PayeeChangeRequest/ApplicationEvent/SmsMessage
    Services/          ApplicationService / ReviewService / AppealService /
                       PaymentService / MockPaymentGateway / SmsService
    Http/Controllers/  认证、批次、申请、审核、申诉、收款人、发放、短信、维护接口
    Console/Commands/  subsidy:sweep-expired
  database/migrations/  含规则 JSON、快照、幂等唯一约束
  tests/Feature/        6 组场景测试
frontend/src/
  pages/               居民/社区/街道三类页面
  api.js / auth.jsx     fetch 封装与登录态
```
