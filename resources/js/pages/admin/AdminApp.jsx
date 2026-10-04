import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { useAuth } from '../../auth.jsx';
import { Badge, Card, Spinner, statusColor } from '../../components/ui.jsx';
import AdminApplicationDetail from './AdminApplicationDetail.jsx';
import BatchesAdmin from './BatchesAdmin.jsx';
import Disbursements from './Disbursements.jsx';

const QUEUES = [
    { key: 'submitted', label: '待社区初审', roles: ['community', 'street'] },
    { key: 'supplement_requested', label: '待居民补件', roles: ['community', 'street'] },
    { key: 'street_pending', label: '待街道复核', roles: ['street'] },
    { key: 'appealing', label: '申诉处理中', roles: ['community', 'street'] },
    { key: 'frozen', label: '冻结待重核', roles: ['street'] },
    { key: 'approved', label: '复核通过/待发放', roles: ['street'] },
    { key: 'disbursed', label: '已到账', roles: ['community', 'street'] },
];

export default function AdminApp() {
    const { user } = useAuth();
    const [tab, setTab] = useState(user.role === 'street' ? 'street_pending' : 'submitted');
    const [items, setItems] = useState(null);
    const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(false);

    const load = useCallback(async (status) => {
        setLoading(true);
        try {
            const data = await api(`admin/applications?status=${status}`);
            setItems(data.applications);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { if (tab !== 'batches' && tab !== 'disbursements') load(tab); }, [tab, load]);

    if (selected) {
        return <AdminApplicationDetail
            id={selected}
            onBack={() => { setSelected(null); load(tab); }}
            onChanged={() => load(tab)}
        />;
    }

    const queues = QUEUES.filter((q) => q.roles.includes(user.role));

    return (
        <div className="space-y-4">
            <div className="flex gap-2 flex-wrap">
                {queues.map((q) => (
                    <button key={q.key} onClick={() => setTab(q.key)}
                        className={`px-3.5 py-1.5 rounded-lg text-sm ${tab === q.key ? 'bg-sky-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                        {q.label}
                    </button>
                ))}
                <span className="w-px bg-slate-200 mx-1" />
                <button onClick={() => setTab('disbursements')}
                    className={`px-3.5 py-1.5 rounded-lg text-sm ${tab === 'disbursements' ? 'bg-sky-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                    发放清单
                </button>
                {user.role === 'street' && (
                    <button onClick={() => setTab('batches')}
                        className={`px-3.5 py-1.5 rounded-lg text-sm ${tab === 'batches' ? 'bg-sky-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                        批次与规则
                    </button>
                )}
            </div>

            {tab === 'batches' ? <BatchesAdmin /> :
             tab === 'disbursements' ? <Disbursements /> : (
                <Card>
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="font-bold text-slate-800">{queues.find((q) => q.key === tab)?.label}</h2>
                        <button onClick={() => load(tab)} className="text-xs text-sky-600 hover:underline">刷新</button>
                    </div>
                    {loading ? <Spinner /> : items?.length === 0 ? (
                        <p className="text-center text-slate-400 py-10 text-sm">当前没有此类申请</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-400 border-b border-slate-100">
                                        <th className="py-2 pr-3">申请编号/批次</th>
                                        <th className="py-2 pr-3">申请人/住址</th>
                                        <th className="py-2 pr-3">供暖方式</th>
                                        <th className="py-2 pr-3">材料</th>
                                        <th className="py-2 pr-3">标记</th>
                                        <th className="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items?.map((a) => (
                                        <tr key={a.id} className="border-b border-slate-50 hover:bg-slate-50">
                                            <td className="py-2.5 pr-3">
                                                <div className="font-medium text-slate-700">{a.apply_no}</div>
                                                <div className="text-xs text-slate-400">{a.batch_code} · {a.created_at}</div>
                                            </td>
                                            <td className="py-2.5 pr-3">
                                                <div>{a.applicant}</div>
                                                <div className="text-xs text-slate-400">{a.address}</div>
                                            </td>
                                            <td className="py-2.5 pr-3">{a.heating_method}</td>
                                            <td className="py-2.5 pr-3 text-xs">
                                                {Object.entries(a.doc_summary || {}).map(([s, n]) => (
                                                    <span key={s} className="inline-block mr-1">
                                                        <Badge color={s === 'expired' ? 'amber' : s === 'submitted' ? 'emerald' : 'slate'}>
                                                            {({ submitted: '有效', expired: '过期', resubmitted: '补交' })[s] || s} {n}
                                                        </Badge>
                                                    </span>
                                                ))}
                                            </td>
                                            <td className="py-2.5 pr-3 space-x-1">
                                                {a.is_late && <Badge color="indigo">补报</Badge>}
                                                {a.frozen && <Badge color="amber">冻结</Badge>}
                                            </td>
                                            <td className="py-2.5">
                                                <button onClick={() => setSelected(a.id)}
                                                    className="text-sky-600 hover:underline text-sm">
                                                    办理 →
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
