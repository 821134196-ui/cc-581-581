import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { Alert, Badge, Button, Card, Field, inputCls, Modal, Spinner } from '../../components/ui.jsx';

export default function BatchesAdmin() {
    const [batches, setBatches] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        const d = await api('admin/batches');
        setBatches(d.batches);
    }, []);
    useEffect(() => { load(); }, [load]);

    if (!batches) return <Spinner />;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="font-bold text-slate-800">低温预警批次与资格规则</h2>
                <Button onClick={() => setShowCreate(true)}>+ 新建批次</Button>
            </div>
            <Alert type="error" onClose={() => setError('')}>{error}</Alert>
            <Alert type="success" onClose={() => setNotice('')}>{notice}</Alert>

            <div className="space-y-3">
                {batches.map((b) => (
                    <Card key={b.id}>
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-semibold text-slate-800">{b.code}</span>
                                    <span className="text-sm text-slate-500">{b.title}</span>
                                    {b.rules_frozen
                                        ? <Badge color="indigo">规则已冻结</Badge>
                                        : <Badge color="amber">规则可修改</Badge>}
                                </div>
                                <p className="text-xs text-slate-400 mt-1">
                                    预警 {b.warning_start} ~ {b.warning_end}｜受理至 {b.apply_deadline}｜补报至 {b.late_apply_deadline}
                                </p>
                            </div>
                            <div className="flex gap-2 shrink-0">
                                {!b.rules_frozen && <Button variant="secondary" onClick={() => setEditing(b)}>修改规则</Button>}
                                {!b.rules_frozen && <FreezeButton batch={b} onDone={(m) => { setNotice(m); load(); }} onError={setError} />}
                            </div>
                        </div>
                        {b.rule && (
                            <div className="mt-3 rounded-lg bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600 grid grid-cols-2 md:grid-cols-4 gap-2">
                                <div>补贴标准：<b>{b.rule.subsidy_amount} 元/{b.rule.subsidy_unit}</b></div>
                                <div>低温阈值：{b.rule.low_temp_threshold}℃</div>
                                <div>材料有效期：{b.rule.doc_valid_days} 天</div>
                                <div>供暖方式：{(b.rule.allowed_heating_methods || []).join('、')}</div>
                                <div className="col-span-2 md:col-span-4">必备材料：{(b.rule.required_documents || []).join('、')}</div>
                            </div>
                        )}
                    </Card>
                ))}
            </div>

            {showCreate && (
                <CreateBatchModal
                    onClose={() => setShowCreate(false)}
                    onDone={() => { setShowCreate(false); setNotice('批次已创建（规则已冻结）。'); load(); }}
                    onError={setError}
                />
            )}
            {editing && (
                <EditRuleModal batch={editing}
                    onClose={() => setEditing(null)}
                    onDone={() => { setEditing(null); setNotice('规则已更新为新版本。注意：已提交申请仍沿用其提交时的快照。'); load(); }}
                    onError={setError}
                />
            )}
        </div>
    );
}

function FreezeButton({ batch, onDone, onError }) {
    const [busy, setBusy] = useState(false);
    return (
        <Button variant="warning" disabled={busy} onClick={async () => {
            if (!confirm('规则冻结后不可修改，且预警结束后的补报也必须按本批规则判断。确认冻结？')) return;
            setBusy(true);
            try {
                const r = await api(`admin/batches/${batch.id}/freeze-rules`, { method: 'POST' });
                onDone(r.message);
            } catch (e) { onError(e.message); } finally { setBusy(false); }
        }}>冻结规则</Button>
    );
}

function RuleFields({ value, onChange }) {
    const set = (k, v) => onChange({ ...value, [k]: v });
    const heatingOpts = ['集中供暖', '自采暖', '电暖', '无供暖'];
    const docOpts = [
        ['idcard', '身份证'], ['residence', '居住证明'], ['bankcard', '收款账户凭证'],
        ['low_income', '低保证明'], ['heating', '供暖缴费/方式证明'], ['household_reg', '户口簿'],
    ];
    const toggleHeating = (m) => {
        const cur = value.allowed_heating_methods || [];
        set('allowed_heating_methods', cur.includes(m) ? cur.filter((x) => x !== m) : [...cur, m]);
    };
    const toggleDoc = (c) => {
        const cur = value.required_documents || [];
        set('required_documents', cur.includes(c) ? cur.filter((x) => x !== c) : [...cur, c]);
    };

    return (
        <div className="space-y-3 text-sm">
            <div className="grid grid-cols-2 gap-3">
                <Field label="补贴金额（元/户）"><input type="number" className={inputCls} value={value.subsidy_amount ?? ''} onChange={(e) => set('subsidy_amount', e.target.value)} /></Field>
                <Field label="低温阈值（℃）"><input type="number" className={inputCls} value={value.low_temp_threshold ?? ''} onChange={(e) => set('low_temp_threshold', e.target.value)} /></Field>
                <Field label="材料有效天数"><input type="number" className={inputCls} value={value.doc_valid_days ?? ''} onChange={(e) => set('doc_valid_days', e.target.value)} /></Field>
            </div>
            <Field label="允许的供暖方式">
                <div className="flex gap-2 flex-wrap mt-1">
                    {heatingOpts.map((m) => (
                        <label key={m} className={`px-3 py-1 rounded-full border text-xs cursor-pointer ${(value.allowed_heating_methods || []).includes(m) ? 'bg-sky-600 text-white border-sky-600' : 'bg-white border-slate-300 text-slate-600'}`}>
                            <input type="checkbox" className="hidden" checked={(value.allowed_heating_methods || []).includes(m)} onChange={() => toggleHeating(m)} />{m}
                        </label>
                    ))}
                </div>
            </Field>
            <Field label="必备材料">
                <div className="flex gap-2 flex-wrap mt-1">
                    {docOpts.map(([c, n]) => (
                        <label key={c} className={`px-3 py-1 rounded-full border text-xs cursor-pointer ${(value.required_documents || []).includes(c) ? 'bg-sky-600 text-white border-sky-600' : 'bg-white border-slate-300 text-slate-600'}`}>
                            <input type="checkbox" className="hidden" checked={(value.required_documents || []).includes(c)} onChange={() => toggleDoc(c)} />{n}
                        </label>
                    ))}
                </div>
            </Field>
            <Field label="居住要求"><input className={inputCls} value={value.residency_requirement ?? ''} onChange={(e) => set('residency_requirement', e.target.value)} /></Field>
        </div>
    );
}

function CreateBatchModal({ onClose, onDone, onError }) {
    const today = new Date().toISOString().slice(0, 10);
    const [form, setForm] = useState({
        code: '', title: '',
        warning_start: today, warning_end: today,
        apply_deadline: today, late_apply_deadline: today,
    });
    const [rule, setRule] = useState({
        low_temp_threshold: -5,
        allowed_heating_methods: ['集中供暖', '自采暖', '电暖'],
        doc_valid_days: 90,
        required_documents: ['idcard', 'residence', 'bankcard'],
        subsidy_amount: 300,
        residency_requirement: '本社区户籍或连续居住满6个月',
    });
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    return (
        <Modal title="新建低温预警批次（创建即冻结规则）" onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>取消</Button>
                <Button disabled={busy} onClick={async () => {
                    setErr(''); setBusy(true);
                    try {
                        await api('admin/batches', { method: 'POST', body: { ...form, rule, freeze: true } });
                        onDone();
                    } catch (e) { setErr(e.message); } finally { setBusy(false); }
                }}>创建并冻结</Button></>}>
            <Alert type="error">{err}</Alert>
            <div className="grid grid-cols-2 gap-3 mb-4">
                <Field label="批次编号" required><input className={inputCls} value={form.code} onChange={set('code')} placeholder="如 2026-W02" /></Field>
                <Field label="批次标题" required><input className={inputCls} value={form.title} onChange={set('title')} /></Field>
                <Field label="预警开始" required><input type="date" className={inputCls} value={form.warning_start} onChange={set('warning_start')} /></Field>
                <Field label="预警结束" required><input type="date" className={inputCls} value={form.warning_end} onChange={set('warning_end')} /></Field>
                <Field label="受理截止" required><input type="date" className={inputCls} value={form.apply_deadline} onChange={set('apply_deadline')} /></Field>
                <Field label="补报截止（预警结束后）" required><input type="date" className={inputCls} value={form.late_apply_deadline} onChange={set('late_apply_deadline')} /></Field>
            </div>
            <RuleFields value={rule} onChange={setRule} />
        </Modal>
    );
}

function EditRuleModal({ batch, onClose, onDone, onError }) {
    const [rule, setRule] = useState({
        low_temp_threshold: batch.rule.low_temp_threshold,
        allowed_heating_methods: batch.rule.allowed_heating_methods,
        doc_valid_days: batch.rule.doc_valid_days,
        required_documents: batch.rule.required_documents,
        subsidy_amount: batch.rule.subsidy_amount,
        residency_requirement: batch.rule.residency_requirement,
    });
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');

    return (
        <Modal title={`修改规则 · ${batch.code}（冻结前可改，已提交申请不受影响）`} onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>取消</Button>
                <Button disabled={busy} onClick={async () => {
                    setErr(''); setBusy(true);
                    try {
                        await api(`admin/batches/${batch.id}/rule`, { method: 'PUT', body: rule });
                        onDone();
                    } catch (e) { setErr(e.message); } finally { setBusy(false); }
                }}>保存新版本</Button></>}>
            <Alert type="error">{err}</Alert>
            <RuleFields value={rule} onChange={setRule} />
        </Modal>
    );
}
