import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { Alert, Badge, Button, Card, Field, inputCls, KeyVal, Modal, Spinner, statusColor } from '../../components/ui.jsx';

export default function ApplicationDetail({ id, onBack }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [modal, setModal] = useState(null); // supplement | appeal | payee

    const load = useCallback(async () => {
        try {
            setData(await api(`my/applications/${id}`));
        } catch (e) {
            setError(e.message);
        }
    }, [id]);

    useEffect(() => { load(); }, [load]);

    async function refresh(msg) {
        await load();
        setNotice(msg);
        setModal(null);
    }

    if (error) return <Alert type="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const { application: a, eligibility } = data;

    return (
        <div className="space-y-4">
            <button onClick={onBack} className="text-sm text-sky-600 hover:underline">← 返回列表</button>

            <Alert type="success" onClose={() => setNotice('')}>{notice}</Alert>

            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2 className="text-lg font-bold text-slate-800">批次 {a.batch_code}</h2>
                            <span className="text-xs text-slate-400">申请编号 {a.apply_no}</span>
                            <Badge color={statusColor(a.status)}>{a.status_label}</Badge>
                            {a.is_late && <Badge color="indigo">预警后补报·按本批规则</Badge>}
                        </div>
                        <p className="text-sm text-slate-500 mt-1">{a.address} · {a.heating_method}</p>
                    </div>
                    <div className="text-right">
                        <div className="text-sm text-slate-400">本户补贴标准</div>
                        <div className="text-xl font-bold text-sky-700">{a.subsidy_amount} 元/户</div>
                    </div>
                </div>

                {a.frozen && (
                    <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                        ⚠️ 申请已冻结：{a.freeze_reason || '等待重新核验'}。重新核验通过后继续发放。
                    </div>
                )}
                {a.merged && (
                    <div className="mt-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-800">
                        ℹ️ 这份申请已合并到本户主申请，审核与发放以主申请为准，不重复受理、不重复发放。
                    </div>
                )}
            </Card>

            {!a.merged && eligibility && (
                <Card>
                    <h3 className="font-semibold text-slate-800 mb-3">资格核验（按申请时冻结的本批规则）</h3>
                    {eligibility.eligible ? (
                        <div className="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2.5 text-sm">
                            ✅ 当前资格核验通过：供暖方式、居住条件与材料均符合本批规则。
                        </div>
                    ) : (
                        <div className="space-y-2 text-sm">
                            {eligibility.missing_docs?.length > 0 && (
                                <div className="rounded-lg bg-rose-50 border border-rose-200 px-4 py-2.5">
                                    <b className="text-rose-700">缺少材料：</b>
                                    {eligibility.missing_docs.map((d) => `${d.doc_name}（${d.reason}）`).join('、')}
                                </div>
                            )}
                            {eligibility.expired_docs?.length > 0 && (
                                <div className="rounded-lg bg-amber-50 border border-amber-200 px-4 py-2.5">
                                    <b className="text-amber-700">材料已过期，请补充：</b>
                                    {eligibility.expired_docs.map((d) => `${d.doc_name}（${d.expire_on} 到期）`).join('、')}
                                </div>
                            )}
                            {eligibility.failed_items?.length > 0 && (
                                <div className="rounded-lg bg-rose-50 border border-rose-200 px-4 py-2.5 text-rose-700">
                                    {eligibility.failed_items.join('；')}
                                </div>
                            )}
                        </div>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        {(a.status === 'supplement_requested' || a.status === 'community_rejected') && (
                            <Button variant="warning" onClick={() => setModal('supplement')}>📎 补充材料</Button>
                        )}
                        {(a.status === 'community_rejected' || a.status === 'rejected') && (
                            <Button variant="danger" onClick={() => setModal('appeal')}>📨 提出申诉</Button>
                        )}
                        {!['disbursed'].includes(a.status) && (
                            <Button variant="secondary" onClick={() => setModal('payee')}>💳 变更收款人</Button>
                        )}
                    </div>
                </Card>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Card>
                    <h3 className="font-semibold text-slate-800 mb-2">申请材料</h3>
                    {a.documents?.length === 0 && <p className="text-sm text-slate-400">暂无材料</p>}
                    <div className="space-y-2">
                        {a.documents?.map((d) => (
                            <div key={d.id} className="flex items-center justify-between text-sm border-b border-slate-100 pb-2">
                                <div>
                                    <span className="text-slate-700">{d.doc_name}</span>
                                    <span className="text-xs text-slate-400 ml-2">签发 {d.issued_on} · 有效至 {d.expire_on}</span>
                                </div>
                                {d.status === 'expired' || d.is_expired_now
                                    ? <Badge color="amber">已过期</Badge>
                                    : d.status === 'resubmitted' ? <Badge color="sky">已补交</Badge>
                                    : <Badge color="emerald">有效</Badge>}
                            </div>
                        ))}
                    </div>

                    <h3 className="font-semibold text-slate-800 mt-5 mb-2">收款信息</h3>
                    <KeyVal k="收款人" v={a.payee?.name} />
                    <KeyVal k="账号" v={a.payee?.account} />
                    <KeyVal k="开户行" v={a.payee?.bank} />
                </Card>

                <Card>
                    <h3 className="font-semibold text-slate-800 mb-3">到账进度</h3>
                    {a.disbursement ? (
                        <>
                            <KeyVal k="发放状态" v={<Badge color={statusColor(a.disbursement.status)}>{a.disbursement.status_label}</Badge>} />
                            <KeyVal k="金额" v={`${a.disbursement.amount} 元`} />
                            <KeyVal k="支付单号" v={a.disbursement.payment_no} />
                            <KeyVal k="到账回执号" v={a.disbursement.receipt_no} />
                            <KeyVal k="到账时间" v={a.disbursement.paid_at} />
                            <p className="text-xs text-slate-400 mt-2">
                                支付回执即使重复送达，系统也只记录一次到账，不会重复发放。
                            </p>
                        </>
                    ) : (
                        <p className="text-sm text-slate-400">街道复核通过后进入发放清单，这里会显示支付与到账进度。</p>
                    )}

                    <h3 className="font-semibold text-slate-800 mt-5 mb-2">审核记录</h3>
                    {a.reviews?.length === 0 && <p className="text-sm text-slate-400">暂无</p>}
                    <div className="space-y-2">
                        {a.reviews?.map((r, i) => (
                            <div key={i} className="text-sm border-l-2 border-slate-200 pl-3">
                                <div className="flex items-center gap-2">
                                    <Badge color={r.level === 'community' ? 'sky' : 'indigo'}>
                                        {r.level === 'community' ? '社区初审' : '街道复核'}
                                    </Badge>
                                    <span className="text-slate-500">{r.result === 'pass' ? '通过' : r.result === 'reject' ? '不通过' : r.result === 'reverify' ? '重新核验通过' : '要求补件'}</span>
                                    <span className="text-xs text-slate-400">{r.reviewer} · {r.created_at}</span>
                                </div>
                                {r.comment && <p className="text-slate-500 mt-0.5">{r.comment}</p>}
                            </div>
                        ))}
                    </div>
                </Card>
            </div>

            <Card>
                <h3 className="font-semibold text-slate-800 mb-3">进度时间线</h3>
                <ol className="relative border-l-2 border-slate-100 ml-2 space-y-4">
                    {a.timeline?.map((t, i) => (
                        <li key={i} className="ml-4">
                            <span className="absolute -left-[7px] w-3 h-3 rounded-full bg-sky-400 mt-1" />
                            <div className="text-sm text-slate-700">{t.message}</div>
                            <div className="text-xs text-slate-400">{t.created_at}</div>
                        </li>
                    ))}
                </ol>
            </Card>

            {modal === 'supplement' && <SupplementModal onClose={() => setModal(null)} onDone={(m) => refresh(m)} app={a} />}
            {modal === 'appeal' && <AppealModal onClose={() => setModal(null)} onDone={(m) => refresh(m)} app={a} />}
            {modal === 'payee' && <PayeeModal onClose={() => setModal(null)} onDone={(m) => refresh(m)} app={a} />}
        </div>
    );
}

function SupplementModal({ app, onClose, onDone }) {
    const required = app.rule_snapshot?.required_documents || [];
    const [docs, setDocs] = useState([{ doc_type: required[0] || '', issued_on: '' }]);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');

    const setDoc = (i, k) => (e) => setDocs(docs.map((d, idx) => idx === i ? { ...d, [k]: e.target.value } : d));

    async function save() {
        setErr('');
        if (docs.some((d) => !d.doc_type || !d.issued_on)) { setErr('请填全材料类型和签发日期'); return; }
        setBusy(true);
        try {
            await api(`my/applications/${app.id}/supplement`, { method: 'POST', body: { documents: docs, note } });
            onDone('补件已提交，沿原申请（编号不变）重新进入审核。');
        } catch (e) { setErr(e.message); } finally { setBusy(false); }
    }

    return (
        <Modal title="补充材料（沿原申请跟进）" onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button onClick={save} disabled={busy}>提交补件</Button></>}>
            <Alert type="error">{err}</Alert>
            <p className="text-xs text-slate-400 mb-3">补件不会产生新申请、不会更换批次，审核员看到的仍是编号 {app.apply_no}。</p>
            <div className="space-y-2">
                {docs.map((d, i) => (
                    <div key={i} className="flex gap-2">
                        <select className={`${inputCls} flex-1`} value={d.doc_type} onChange={setDoc(i, 'doc_type')}>
                            {['idcard', 'residence', 'bankcard', 'low_income', 'heating', 'household_reg'].map((c) => {
                                const names = { idcard: '身份证', residence: '居住证明', bankcard: '收款账户凭证', low_income: '低保证明', heating: '供暖缴费/方式证明', household_reg: '户口簿' };
                                return <option key={c} value={c}>{names[c]}</option>;
                            })}
                        </select>
                        <input type="date" className={`${inputCls} w-44`} value={d.issued_on} onChange={setDoc(i, 'issued_on')} />
                    </div>
                ))}
            </div>
            <Field label="补件说明" >
                <textarea className={`${inputCls} mt-1`} rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
        </Modal>
    );
}

function AppealModal({ app, onClose, onDone }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');

    async function save() {
        setErr('');
        if (reason.trim().length < 5) { setErr('请填写至少 5 个字的申诉理由'); return; }
        setBusy(true);
        try {
            await api(`my/applications/${app.id}/appeal`, { method: 'POST', body: { reason } });
            onDone('申诉已提交，沿原申请由原审核层级处理。');
        } catch (e) { setErr(e.message); } finally { setBusy(false); }
    }

    return (
        <Modal title="提出申诉（沿原申请跟进）" onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button variant="danger" onClick={save} disabled={busy}>提交申诉</Button></>}>
        <Alert type="error">{err}</Alert>
            <Field label="申诉理由" required>
                <textarea className={`${inputCls} mt-1`} rows={4} value={reason} onChange={(e) => setReason(e.target.value)}
                    placeholder="说明您认为审核结论有误的原因，申诉成立后将沿原申请重新审核，不另立新批次。" />
            </Field>
        </Modal>
    );
}

function PayeeModal({ app, onClose, onDone }) {
    const [payee, setPayee] = useState({ name: app.payee?.name || '', account: app.payee?.account || '', bank: app.payee?.bank || '' });
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');

    async function save() {
        setErr('');
        setBusy(true);
        try {
            await api(`my/applications/${app.id}/payee`, { method: 'POST', body: payee });
            onDone('收款人已更新。若申请已进入审核/发放环节，系统已冻结并等待街道重新核验。');
        } catch (e) { setErr(e.message); } finally { setBusy(false); }
    }

    return (
        <Modal title="变更收款人" onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>取消</Button><Button variant="warning" onClick={save} disabled={busy}>确认变更</Button></>}>
            <Alert type="error">{err}</Alert>
            <div className="space-y-3">
                <Field label="新收款人姓名" required><input className={inputCls} value={payee.name} onChange={(e) => setPayee({ ...payee, name: e.target.value })} /></Field>
                <Field label="新收款账号" required><input className={inputCls} value={payee.account} onChange={(e) => setPayee({ ...payee, account: e.target.value })} /></Field>
                <Field label="开户银行"><input className={inputCls} value={payee.bank} onChange={(e) => setPayee({ ...payee, bank: e.target.value })} /></Field>
                <p className="text-xs text-amber-600">进入社区初审之后变更收款人，申请将被冻结，须街道重新核验通过才继续发放。</p>
            </div>
        </Modal>
    );
}
