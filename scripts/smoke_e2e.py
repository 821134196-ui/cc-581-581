#!/usr/bin/env python3
"""
端到端冒烟测试：通过 HTTP API 走通全部关键业务规则。
用法： python3 scripts/smoke_e2e.py [base_url]
"""
import json
import sys
import time
import urllib.request
import urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8000"
RUN = f"{int(time.time()) % 1000000:06d}"  # 每次运行唯一，避免与历史测试数据撞户
PASS, FAIL = 0, 0


def call(method, path, token=None, body=None, expect_error=False):
    url = f"{BASE}/api/{path.lstrip('/')}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req) as resp:
            payload = json.loads(resp.read())
            if expect_error:
                boom(f"期望 {path} 报错但成功了: {payload}")
            return payload
    except urllib.error.HTTPError as e:
        payload = json.loads(e.read())
        if not expect_error:
            boom(f"{method} {path} -> {e.code}: {payload}")
        return payload


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print(f"  ✓ {name}")
    else:
        FAIL += 1
        print(f"  ✗ {name} {detail}")


def boom(msg):
    global FAIL
    FAIL += 1
    print(f"  ✗✗ 致命: {msg}")
    sys.exit(1)


def login(email, password="password"):
    return call("POST", "auth/login", body={"email": email, "password": password})["token"]


def docs(issued="2026-09-20", residence_issued=None):
    return [
        {"doc_type": "idcard", "issued_on": issued},
        {"doc_type": "residence", "issued_on": residence_issued or issued},
        {"doc_type": "bankcard", "issued_on": issued},
    ]


def submit(token, batch_id, address, **kw):
    payload = {
        "batch_id": batch_id,
        "address": address,
        "household_head_name": kw.get("head", "测试户主"),
        "household_head_idcard": kw.get("head_id", "110101199001011111"),
        "heating_method": "自采暖",
        "residency_met": True,
        "payee": {"name": kw.get("payee", "测试户主"), "account": kw.get("account", "6222000000000111"), "bank": "模拟银行"},
        "documents": kw.get("documents", docs()),
    }
    payload.update({k: v for k, v in kw.items() if k in ("documents",)})
    return call("POST", "my/applications", token, payload)


def main():
    print(f"目标：{BASE}")
    community = login("community@demo.local")
    street = login("street@demo.local")
    street2 = login("street2@demo.local")
    zhang = login("zhang@demo.local")
    spouse = login("zhang-spouse@demo.local")
    liu = login("liu@demo.local")

    batches = call("GET", "batches", zhang)["batches"]
    batch_id = batches[0]["id"]
    print(f"使用批次 {batches[0]['code']}（规则冻结={batches[0]['rules_frozen']}）")

    # 1. 一户多人重复申请合并 ------------------------------------------------
    print("\n[1] 一户多名成员重复申请 → 自动合并，只发一笔")
    r1 = submit(zhang, batch_id, f"冒烟测试路 {RUN} 号", head="张居民", head_id="11010119900101001X",
                payee="张居民", account="6222000000000001")
    check("户主申请提交成功", r1["merged"] is False, r1)
    app_id = r1["application"]["id"]

    r2 = submit(spouse, batch_id, f"冒烟测试路{RUN}号", head="张居民", head_id="11010119900101001X",
                payee="张居民", account="6222000000000001")
    check("配偶同户重复申请被合并", r2["merged"] is True, r2)
    check("合并到原申请", r2["application"]["id"] == app_id)

    # 2. 两级审核（初审/复核不同人） -----------------------------------------
    print("\n[2] 社区初审 → 街道复核")
    call("POST", f"admin/applications/{app_id}/community-review", community,
         {"result": "pass", "comment": "材料齐全，资格符合"})
    # 同人复核被角色层拒绝
    deny = call("POST", f"admin/applications/{app_id}/street-review", community,
                {"result": "pass"}, expect_error=True)
    check("社区账号不能执行街道复核", deny["error_code"] == "forbidden_role", deny)
    call("POST", f"admin/applications/{app_id}/street-review", street,
         {"result": "pass", "comment": "复核无误"})
    detail = call("GET", f"my/applications/{app_id}", zhang)["application"]
    check("状态为复核通过", detail["status"] == "approved", detail["status"])

    # 3. 发放与重复回执幂等 ---------------------------------------------------
    print("\n[3] 发起发放；支付回执重复送达只到账一次")
    issue = call("POST", "admin/disbursements/issue", street, {"batch_id": batch_id})
    payment_no = None
    dis = call("GET", f"admin/disbursements?batch_id={batch_id}", street)["disbursements"]
    for d in dis:
        if d["apply_no"] == detail["apply_no"]:
            payment_no = d["payment_no"]
    check("支付已发起", payment_no is not None, issue["message"])

    rc1 = call("POST", "sim/payment/receipt", None, {"payment_no": payment_no, "receipt_no": f"RCP-SMOKE-{RUN}"})
    # 一键启动时 sim:worker 可能已自动回执，此时 rc1 也会被识别为重复——同样合法
    auto_received = rc1["duplicate"] is True
    check("到账已成立（或首次回执入账）", rc1["disbursement"]["status"] == "paid", rc1)
    rc2 = call("POST", "sim/payment/receipt", None, {"payment_no": payment_no, "receipt_no": f"RCP-SMOKE-{RUN}"})
    rc3 = call("POST", "sim/payment/receipt", None, {"payment_no": payment_no, "receipt_no": f"RCP-SMOKE-{RUN}"})
    check("第二次回执识别为重复", rc2["duplicate"] is True)
    check("第三次回执识别为重复", rc3["duplicate"] is True)
    expect_count = 4 if auto_received else 3  # worker 自动回执 1 次 + 手工 3 次，或仅手工 3 次
    check(f"重复送达均留痕但只入账一次（receipt_count={expect_count}）", rc3["disbursement"]["receipt_count"] == expect_count, rc3["disbursement"])
    detail = call("GET", f"my/applications/{app_id}", zhang)["application"]
    check("居民看到已到账", detail["status"] == "disbursed" and detail["disbursement"]["status"] == "paid")

    # 4. 材料过期 → 补件 → 沿原申请重审 --------------------------------------
    print("\n[4] 材料过期提示补充，补件沿原申请跟进")
    r = submit(liu, batch_id, f"冒烟测试路 {RUN} 号-2",
               head="刘居民", head_id="11010119880808111X", payee="刘居民", account=f"6222{RUN}04",
               documents=docs(residence_issued="2025-06-01"))
    app2 = r["application"]["id"]
    no_before = r["application"]["apply_no"]
    call("POST", f"admin/applications/{app2}/community-review", community, {"result": "pass"})
    st = call("GET", f"admin/applications/{app2}", community)["application"]["status"]
    check("过期材料被退回补件", st == "supplement_requested", st)
    # liu 已在开头登录
    call("POST", f"my/applications/{app2}/supplement", liu,
         {"documents": [{"doc_type": "residence", "issued_on": "2026-10-01"}], "note": "补交新居住证明"})
    detail2 = call("GET", f"my/applications/{app2}", liu)["application"]
    check("补件后回到待初审", detail2["status"] == "submitted", detail2["status"])
    check("申请编号/批次不变", detail2["apply_no"] == no_before and detail2["batch_code"] == batches[0]["code"])
    call("POST", f"admin/applications/{app2}/community-review", community, {"result": "pass"})
    call("POST", f"admin/applications/{app2}/street-review", street, {"result": "pass"})
    check("补件后复核通过", call("GET", f"my/applications/{app2}", liu)["application"]["status"] == "approved")

    # 5. 收款人变更冻结 → 重新核验 -------------------------------------------
    print("\n[5] 复核通过后变更收款人 → 冻结 → 街道（另一人）重新核验")
    call("POST", f"my/applications/{app2}/payee", liu,
         {"name": "刘配偶", "account": "622200000005", "bank": "模拟银行"})
    d = call("GET", f"my/applications/{app2}", liu)["application"]
    check("申请已冻结待重核", d["status"] == "frozen" and d["reverify_required"] is True, d["status"])
    call("POST", f"admin/applications/{app2}/reverify", street2, {"result": "pass", "comment": "新账户核验通过"})
    d = call("GET", f"my/applications/{app2}", liu)["application"]
    check("重核通过恢复发放", d["status"] == "approved" and d["payee"]["account"] == "622200000005")

    # 6. 申诉沿原申请 --------------------------------------------------------
    print("\n[6] 申诉沿原申请跟进")
    r = submit(liu, batch_id, f"冒烟测试路 {RUN} 号-3",
               head="刘居民二", head_id=f"110101198808080{int(RUN[-2:])%100:02d}X", payee="刘居民二", account=f"6222{RUN}06")
    app3 = r["application"]["id"]
    no3 = r["application"]["apply_no"]
    call("POST", f"admin/applications/{app3}/community-review", community,
         {"result": "reject", "comment": "供暖方式存疑"})
    call("POST", f"my/applications/{app3}/appeal", liu, {"reason": "我家确为自采暖，可提供缴费凭证"})
    appeal_id = call("GET", f"my/applications/{app3}", liu)["application"]["follow_ups"][-1]["id"]
    call("POST", f"admin/applications/{app3}/appeals/{appeal_id}", community,
         {"accepted": True, "comment": "情况属实，重新审核"})
    d = call("GET", f"my/applications/{app3}", liu)["application"]
    check("申诉成立回到初审", d["status"] == "submitted", d["status"])
    check("申诉不换申请编号", d["apply_no"] == no3)

    # 7. 越权访问 ------------------------------------------------------------
    print("\n[7] 居民只能看本户")
    deny = call("GET", f"my/applications/{app3}", zhang, expect_error=True)
    check("跨户访问被拒(403)", isinstance(deny, dict), deny)

    print(f"\n结果：通过 {PASS}，失败 {FAIL}")
    sys.exit(1 if FAIL else 0)


if __name__ == "__main__":
    main()
