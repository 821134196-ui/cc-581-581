import React, { useEffect, useState } from 'react';
import { api } from '../../api.js';
import { Alert, Button, Card, Field, inputCls, Spinner } from '../../components/ui.jsx';

const DOC_TYPES = [
    { code: 'idcard', name: '身份证' },
    { code: 'residence', name: '居住证明' },
    { code: 'bankcard', name: '收款账户凭证' },
    { code: 'low_income', name: '低保证明' },
    { code: 'heating', name: '供暖缴费/方式证明' },
    { code: 'household_reg', name: '户口簿' },
];

const HEATING_METHODS = ['集中供暖', '自采暖', '电暖', '无供暖'];

export default function NewApplication({ onDone }) {
    const [batches, setBatches] = useState(null);
    const [batchId, setBatchId] = useState('');
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({
        address: '',
        household_head_name: '',
        household_head_idcard: '',
        heating_method: '',
        residency_met: true,
        payee: { name: '', account: '', bank: '' },
    });
    const [docs, setDocs] = useState([{ doc_type: '', issued_on: '' }]);

    useEffect(() => {
        api('batches').then(({ batches }) => {
            setBatches(batches);
            const first = batches[0];
            if (first) setBatchId(String(first.id));
        }).catch((e) => setError(e.message));
    }, []);

    const batch = batches?.find((b) => String(b.id) === batchId);

    // 按批次规则生成必备材料行
    useEffect(() => {
        if (batch?.rule) {
            setDocs(batch.rule.required_documents.map((c) => ({ doc_type: c, issued_on: '' })));
        }
    }, [batchId]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const setPayee = (k) => (e) => setForm({ ...form, payee: { ...form.payee, [k]: e.target.value } });
    const setDoc = (i, k) => (e) => setDocs(docs.map((d, idx) => idx === i ? { ...d, [k]: e.target.value } : d));
    const addDoc = () => setDocs([...docs, { doc_type: '', issued_on: '' }]);
    const removeDoc = (i) => setDocs(docs.filter((_, idx) => idx !== i));

    async function submit(e) {
        e.preventDefault();
        setError(''); setSuccess('');
        if (!form.heating_method) { setError('请选择供暖方式'); return; }
        if (docs.some((d) => !d.doc_type || !d.issued_on)) { setError('每份材料都要填写类型和签发日期'); return; }

        setBusy(true);
        try {
            const data = await api('my/applications', {
                method: 'POST',
                body: { batch_id: Number(batchId), ...form, documents: docs },
            });
            setSuccess(data.message + (data.merged ? '（申请编号：' + data.application.apply_no + '）' : ''));
            setTimeout(onDone, 1200);
        } catch (err) {
            setError(err.message + (err.context ? `（${JSON.stringify(err.context)}）` : ''));
        } finally {
            setBusy(false);
        }
    }

    if (!batches) return <Spinner />;

    return (
        <Card>
            <h2 className="font-bold text-lg text-slate-800 mb-1">提交低温取暖补贴申请</h2>
            <p className="text-xs text-slate-400 mb-4">
                提交时系统会保存本批资格规则快照与您的申请资料；同户成员重复提交将自动合并。
            </p>

            <Alert type="error">{error}</Alert>
            <Alert type="success">{success}</Alert>

            <form onSubmit={submit} className="space-y-5">
                <Field label="低温预警批次" required>
                    <select className={inputCls} value={batchId} onChange={(e) => setBatchId(e.target.value)}>
                        {batches.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.code} · {b.title}
                                {b.status !== 'open' ? '（已关闭）' : ''}
                            </option>
                        ))}
                    </select>
                </Field>

                {batch && (
                    <div className="rounded-lg bg-slate-50 border border-slate-200 p-4 text-sm space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="font-medium text-slate-700">本批规则 {batch.rule && <span className="text-xs text-slate-400">v{batch.rule.version}</span>}</span>
                            {batch.rules_frozen
                                ? <span className="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">规则已冻结</span>
                                : <span className="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700">规则未冻结</span>}
                        </div>
                        {batch.rule ? (
                            <>
                                <p className="text-slate-500">
                                    低温阈值 {batch.rule.low_temp_threshold}℃ · 补贴 <b className="text-sky-700">{batch.rule.subsidy_amount} 元/{batch.rule.subsidy_unit}</b>
                                </p>
                                <p className="text-slate-500">允许供暖方式：{batch.rule.allowed_heating_methods.join('、')}</p>
                                <p className="text-slate-500">材料有效期：签发后 {batch.rule.doc_valid_days} 天；居住要求：{batch.rule.residency_requirement}</p>
                                <p className="text-slate-500">
                                    预警期 {batch.warning_start} ~ {batch.warning_end}；正常受理至 {batch.apply_deadline}；
                                    <b>预警后补报至 {batch.late_apply_deadline}（仍按本批规则）</b>
                                </p>
                            </>
                        ) : <p className="text-amber-600">该批次尚未配置规则，暂时无法提交。</p>}
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="家庭住址" required>
                        <input className={inputCls} value={form.address} onChange={set('address')} placeholder="如：幸福里小区1号楼101室" required />
                    </Field>
                    <Field label="供暖方式" required>
                        <select className={inputCls} value={form.heating_method} onChange={set('heating_method')} required>
                            <option value="">请选择</option>
                            {HEATING_METHODS.map((m) => {
                                const allowed = batch?.rule?.allowed_heating_methods?.includes(m);
                                return <option key={m} value={m} disabled={batch?.rule && !allowed}>{m}{batch?.rule && !allowed ? '（本批不适用）' : ''}</option>;
                            })}
                        </select>
                    </Field>
                    <Field label="户主姓名">
                        <input className={inputCls} value={form.household_head_name} onChange={set('household_head_name')} placeholder="默认与您一致" />
                    </Field>
                    <Field label="户主身份证号" hint="与住址一起用于判断“同一户”，重复申请将合并">
                        <input className={inputCls} value={form.household_head_idcard} onChange={set('household_head_idcard')} />
                    </Field>
                </div>

                <label className="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" className="mt-1" checked={form.residency_met}
                        onChange={(e) => setForm({ ...form, residency_met: e.target.checked })} />
                    <span>本人/本户符合居住要求：{batch?.rule?.residency_requirement || '本社区户籍或连续居住满6个月'}</span>
                </label>

                <div className="border-t border-slate-100 pt-4">
                    <h3 className="font-medium text-slate-700 mb-3">收款信息</h3>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Field label="收款人姓名" required>
                            <input className={inputCls} value={form.payee.name} onChange={setPayee('name')} required />
                        </Field>
                        <Field label="收款账号" required hint="进入审核后变更收款人将冻结并重新核验">
                            <input className={inputCls} value={form.payee.account} onChange={setPayee('account')} required />
                        </Field>
                        <Field label="开户银行">
                            <input className={inputCls} value={form.payee.bank} onChange={setPayee('bank')} />
                        </Field>
                    </div>
                </div>

                <div className="border-t border-slate-100 pt-4">
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="font-medium text-slate-700">申请材料 <span className="text-xs text-slate-400">（材料过期将提示补交）</span></h3>
                        <Button type="button" variant="secondary" onClick={addDoc}>+ 添加材料</Button>
                    </div>
                    <div className="space-y-2">
                        {docs.map((d, i) => {
                            const locked = batch?.rule?.required_documents?.includes(d.doc_type);
                            return (
                                <div key={i} className="flex gap-2 items-center">
                                    <select className={`${inputCls} flex-1`} value={d.doc_type} onChange={setDoc(i, 'doc_type')}>
                                        <option value="">选择材料类型</option>
                                        {DOC_TYPES.map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}
                                    </select>
                                    <input type="date" className={`${inputCls} w-44`} value={d.issued_on} onChange={setDoc(i, 'issued_on')} />
                                    {!locked && <Button type="button" variant="ghost" onClick={() => removeDoc(i)}>删除</Button>}
                                </div>
                            );
                        })}
                    </div>
                    {batch?.rule && (
                        <p className="text-xs text-amber-600 mt-2">
                            注意：材料到期日按签发日 + {batch.rule.doc_valid_days} 天计算，审核时已过期的材料会被要求补充。
                        </p>
                    )}
                </div>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onDone}>取消</Button>
                    <Button type="submit" disabled={busy || !batch?.rule}>{busy ? '提交中…' : '提交申请'}</Button>
                </div>
            </form>
        </Card>
    );
}
