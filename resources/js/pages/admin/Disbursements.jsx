import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { useAuth } from '../../auth.jsx';
import { Alert, Badge, Button, Card, Modal, Spinner, statusColor } from '../../components/ui.jsx';

export default function Disbursements() {
    const { user } = useAuth();
    const isStreet = user.role === 'street';
    const [rows, setRows] = useState(null);
    const [batches, setBatches] = useState([]);
    const [batchId, setBatchId] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [receiptTarget, setReceiptTarget] = useState(null);

    const load = useCallback(async () => {
        const q = new URLSearchParams();
        if (batchId) q.set('batch_id', batchId);
        if (statusFilter) q.set('status', statusFilter);
        const d = await api(`admin/disbursements?${q.toString()}`);
        setRows(d.disbursements);
    }, [batchId, statusFilter]);

    useEffect(() => {
        api('admin/batches').then((d) => {
            setBatches(d.batches);
            if (d.batches[0]) setBatchId(String(d.batches[0].id));
        }).catch(setError);
    }, []);

    useEffect(() => { if (batchId) load(); }, [load, batchId]);

    async function issue() {
        setBusy(true); setError(''); setNotice('');
        try {
            const r = await api('admin/disbursements/issue', { method: 'POST', body: { batch_id: Number(batchId) } });
            setNotice(r.message);
            load();
        } catch (e) { setError(e.message); } finally { setBusy(false); }
    }

    async function sendReceipt(paymentNo, receiptNo) {
        setBusy(true); setError('');
        try {
            const r = await api('sim/payment/receipt', { method: 'POST', body: { payment_no: paymentNo, receipt_no: receiptNo } });
            setNotice(r.message);
            load();
        } catch (e) { setError(e.message); } finally { setBusy(false); setReceiptTarget(null); }
    }

    if (rows === null) return <Spinner />;

    return (
        <div className="space-y-4">
            <Card>
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex gap-3 items-end">
                        <label className="text-sm">
                            <span className="block text-xs text-slate-400 mb-1">批次</span>
                            <select className="border border-slate-300 rounded-lg px-3 py-2 text-sm" value={batchId} onChange={(e) => setBatchId(e.target.value)}>
                                {batches.map((b) => <option key={b.id} value={b.id}>{b.code}</option>)}
                            </select>
                        </label>
                        <label className="text-sm">
                            <span className="block text-xs text-slate-400 mb-1">状态</span>
                            <select className="border border-slate-300 rounded-lg px-3 py-2 text-sm" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                                <option value="">全部</option>
                                <option value="listed">已入清单</option>
                                <option value="paying">支付处理中</option>
                                <option value="paid">已到账</option>
                                <option value="frozen">冻结暂停</option>
                            </select>
                        </label>
                        <button onClick={load} className="text-sm text-sky-600 hover:underline pb-2">刷新</button>
                    </div>
                    {isStreet && (
                        <Button onClick={issue} disabled={busy}>向模拟支付服务发起本批发放</Button>
                    )}
                </div>
                <p className="text-xs text-slate-400 mt-2">
                    本地模拟：发起后 sim:worker 会自动回执到账；也可用下方「模拟回执」重复发送同一回执号验证幂等——重复回执只记一次到账。
                </p>
            </Card>

            <Alert type="error" onClose={() => setError('')}>{error}</Alert>
            <Alert type="success" onClose={() => setNotice('')}>{notice}</Alert>

            <Card>
                {rows.length === 0 ? (
                    <p className="text-center text-slate-400 py-10 text-sm">该批次暂无发放记录（街道复核通过后自动入清单）</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-slate-400 border-b border-slate-100">
                                    <th className="py-2 pr-3">申请</th>
                                    <th className="py-2 pr-3">收款人</th>
                                    <th className="py-2 pr-3">金额</th>
                                    <th className="py-2 pr-3">状态</th>
                                    <th className="py-2 pr-3">支付/回执</th>
                                    <th className="py-2 pr-3">回执次数</th>
                                    <th className="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((d) => (
                                    <tr key={d.id} className="border-b border-slate-50 align-top">
                                        <td className="py-2.5 pr-3">
                                            <div className="font-medium text-slate-700">{d.apply_no}</div>
                                            <div className="text-xs text-slate-400">{d.address}</div>
                                        </td>
                                        <td className="py-2.5 pr-3">
                                            <div>{d.payee_name}</div>
                                            <div className="text-xs text-slate-400">{d.payee_account}</div>
                                        </td>
                                        <td className="py-2.5 pr-3">{d.amount} 元</td>
                                        <td className="py-2.5 pr-3"><Badge color={statusColor(d.status)}>{d.status_label}</Badge></td>
                                        <td className="py-2.5 pr-3 text-xs text-slate-500">
                                            <div>支付单：{d.payment_no || '—'}</div>
                                            <div>回执号：{d.receipt_no || '—'}</div>
                                            {d.paid_at && <div className="text-emerald-600">到账：{d.paid_at}</div>}
                                        </td>
                                        <td className="py-2.5 pr-3">{d.receipt_count}</td>
                                        <td className="py-2.5">
                                            {(d.status === 'paying' || d.status === 'paid') && isStreet && (
                                                <Button variant="secondary" onClick={() => setReceiptTarget(d)}>模拟回执</Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            {receiptTarget && (
                <ReceiptModal
                    disbursement={receiptTarget}
                    busy={busy}
                    onClose={() => setReceiptTarget(null)}
                    onSend={sendReceipt}
                />
            )}
        </div>
    );
}

function ReceiptModal({ disbursement, busy, onClose, onSend }) {
    const [receiptNo, setReceiptNo] = useState(disbursement.receipt_no || `RCP-${Math.random().toString(36).slice(2, 10).toUpperCase()}`);
    return (
        <Modal title="模拟支付公司送达回执" onClose={onClose}
            footer={<>
                <Button variant="secondary" onClick={onClose}>关闭</Button>
            </>}>
            <div className="space-y-3">
                <div className="text-sm text-slate-500">支付单号：{disbursement.payment_no}</div>
                <label className="block text-sm">
                    <span className="block text-slate-600 mb-1">回执号</span>
                    <input className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" value={receiptNo} onChange={(e) => setReceiptNo(e.target.value)} />
                </label>
                <div className="flex gap-2">
                    <Button disabled={busy} onClick={() => onSend(disbursement.payment_no, receiptNo)}>
                        {disbursement.status === 'paid' ? '再次送达同一回执（验证重复被忽略）' : '送达回执（确认到账）'}
                    </Button>
                </div>
                <p className="text-xs text-slate-400">
                    对已到账记录重复点击：系统以回执号幂等，只增加“送达次数”留痕，不会第二次记账或发钱。
                </p>
            </div>
        </Modal>
    );
}
