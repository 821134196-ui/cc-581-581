import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { Alert, Badge, Button, Card, Spinner, statusColor } from '../../components/ui.jsx';
import NewApplication from './NewApplication.jsx';
import ApplicationDetail from './ApplicationDetail.jsx';

export default function ResidentApp() {
    const [tab, setTab] = useState('list'); // list | new
    const [apps, setApps] = useState(null);
    const [selected, setSelected] = useState(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const data = await api('my/applications');
            setApps(data.applications);
        } catch (e) {
            setError(e.message);
        }
    }, []);

    useEffect(() => { load(); }, [load, tab]);

    if (selected) {
        return <ApplicationDetail id={selected.id} onBack={() => { setSelected(null); load(); }} onChanged={load} />;
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-4">
                <div className="flex gap-2">
                    <TabBtn active={tab === 'list'} onClick={() => setTab('list')}>我的申请与到账</TabBtn>
                    <TabBtn active={tab === 'new'} onClick={() => setTab('new')}>提交新申请</TabBtn>
                </div>
            </div>

            <Alert type="error" onClose={() => setError('')}>{error}</Alert>

            {tab === 'new' ? (
                <NewApplication
                    onDone={() => { setTab('list'); }}
                />
            ) : apps === null ? <Spinner /> : (
                <ApplicationList apps={apps} onOpen={setSelected} onReload={load} />
            )}
        </div>
    );
}

function TabBtn({ active, children, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${active ? 'bg-sky-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`}
        >{children}</button>
    );
}

function ApplicationList({ apps, onOpen }) {
    if (apps.length === 0) {
        return (
            <Card className="text-center py-12 text-slate-400">
                <div className="text-4xl mb-3">📄</div>
                <p>还没有申请记录，点击「提交新申请」开始办理。</p>
            </Card>
        );
    }

    return (
        <div className="space-y-3">
            {apps.map((a) => (
                <Card key={a.id} className="hover:border-sky-300 cursor-pointer transition" >
                    <div onClick={() => onOpen(a)} className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="font-semibold text-slate-800">{a.batch_code}</span>
                                <span className="text-xs text-slate-400">{a.apply_no}</span>
                                {a.is_late && <Badge color="indigo">预警后补报·按本批规则</Badge>}
                                {a.frozen && <Badge color="amber">已冻结</Badge>}
                            </div>
                            <div className="text-sm text-slate-500 mt-1">
                                {a.address} · {a.heating_method} · 补贴 {a.subsidy_amount} 元/户
                            </div>
                            <div className="text-xs text-slate-400 mt-1">提交于 {a.created_at}</div>
                        </div>
                        <div className="text-right shrink-0">
                            <Badge color={statusColor(a.status)}>{a.status_label}</Badge>
                            <div className="mt-2 text-xs">
                                {a.disbursement ? (
                                    <span className={a.disbursement.status === 'paid' ? 'text-emerald-600 font-medium' : 'text-slate-500'}>
                                        到账状态：{a.disbursement.status_label}
                                        {a.disbursement.paid_at && `（${a.disbursement.paid_at}）`}
                                    </span>
                                ) : <span className="text-slate-300">尚未进入发放环节</span>}
                            </div>
                        </div>
                    </div>
                </Card>
            ))}
        </div>
    );
}
