import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../../api.js';
import { useAuth } from '../../auth.jsx';
import { Alert, Badge, Button, Card, Field, inputCls, KeyVal, Modal, Spinner, statusColor } from '../../components/ui.jsx';

export default function AdminApplicationDetail({ id, onBack, onChanged }) {
    const { user } = useAuth();
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [action, setAction] = useState(null); // {kind, endpoint}
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try { setData(await api(`admin/applications/${id}`)); } catch (e) { setError(e.message); }
    }, [id]);

    useEffect(() => { load(); }, [load]);

    async function callApi(path, body) {
        setBusy(true);
        setError('');
        try {
            const r = await api(path, { method: 'POST', body });
            setNotice(r.message);
            setAction(null);
            await load();
            onChanged?.();
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }

    if (error && !data) return <Alert type="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const a = data.application;
    const isCommunity = user.role === 'community';
    const isStreet = user.role === 'street';
    const communityReviewerId = a.reviews.filter((r) => r.level === 'community' && r.result === 'pass').at(-1)?.reviewer_id;
    const iAmInitialReviewer = communityReviewerId === user.id;

    const pendingAppeal = a.follow_ups.find((f) => f.type === 'appeal' && f.status === 'pending');

    return (
        <div className="space-y-4">
            <button onClick={onBack} className="text-sm text-sky-600 hover:underline">← 返回队列</button>
            <Alert type="success" onClose={() => setNotice('')}>{notice}</Alert>
            <Alert type="error" onClose={() => setError('')}>{error}</Alert>

            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2 className="text-lg font-bold text-slate-800">{a.apply_no}</h2>
                            <Badge color={statusColor(a.status)}>{a.status_label}</Badge>
                            {a.is_late && <Badge color="indigo">预警后补报 · 按本批规则</Badge>}
                            {a.frozen && <Badge color="amber">冻结中</Badge>}
                        </div>
                        <p className="text-sm text-slate-500 mt-1">{a.batch.code} · {a.batch.title}</p>
                    </div>
                    <div className="text-right text-sm">
                        <div className="text-slate-400">补贴标准（规则快照）</div>
                        <div className="text-lg font-bold text-sky-700">{a.rule_snapshot.subsidy_amount} 元/{a.rule_snapshot.subsidy_unit}</div>
                    </div>
                </div>

                {a.frozen && (
                    <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                        ⚠️ 冻结原因：{a.freeze_reason}。请街道完成收款人变更的重新核验。
                    </div>
                )}

                {/* 操作区 */}
                <div className="mt-4 pt-4 border-t border-slate-100 flex flex-wrap gap-2">
                    {isCommunity && a.status === 'submitted' && (
                        <>
                            <Button variant="success" onClick={() => setAction({ kind: 'community', result: 'pass', title: '社区初审通过' })}>初审通过 → 转街道复核</Button>
                            <Button variant="warning" onClick={() => setAction({ kind: 'community', result: 'supplement', title: '退回补充材料' })}>要求补件</Button>
                            <Button variant="danger" onClick={() => setAction({ kind: 'community', result: 'reject', title: '初审不通过' })}>初审不通过</Button>
                        </>
                    )}
                    {isStreet && a.status === 'street_pending' && (
                        <>
                            {iAmInitialReviewer && (
                                <div className="w-full text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">
                                    您是本申请的初审人，系统禁止同一人复核同一份申请，请用其他街道账号办理。
                                </div>
                            )}
                            <Button variant="success" disabled={iAmInitialReviewer}
                                onClick={() => setAction({ kind: 'street', result: 'pass', title: '街道复核通过' })}>
                                复核通过 → 进入发放清单
                            </Button>
                            <Button variant="danger" disabled={iAmInitialReviewer}
                                onClick={() => setAction({ kind: 'street', result: 'reject', title: '复核不通过' })}>
                                复核不通过
                            </Button>
                        </>
                    )}
                    {isStreet && a.status === 'frozen' && a.reverify_required && (
                        <>
                            {iAmInitialReviewer && <div className="w-full text-xs text-rose-600">您做过该申请初审，不能由您重新核验。</div>}
                            <Button variant="success" disabled={iAmInitialReviewer}
                                onClick={() => setAction({ kind: 'reverify', result: 'pass', title: '重新核验通过' })}>
                                重新核验通过（解冻继续发放）
                            </Button>
                            <Button variant="danger" disabled={iAmInitialReviewer}
                                onClick={() => setAction({ kind: 'reverify', result: 'reject', title: '重新核验不通过' })}>
                                重新核验不通过
                            </Button>
                        </>
                    )}
                    {a.status === 'appealing' && pendingAppeal && (
                        ((isCommunity && !a.reviews.some((r) => r.level === 'street')) || isStreet) && (
                            <>
                                <Button variant="success" onClick={() => setAction({ kind: 'appeal', accepted: true, title: '申诉成立', followUpId: pendingAppeal.id })}>
                                    认定申诉成立（沿原申请重审）
                                </Button>
                                <Button variant="danger" onClick={() => setAction({ kind: 'appeal', accepted: false, title: '申诉不成立', followUpId: pendingAppeal.id })}>
                                    申诉不成立（维持原结论）
                                </Button>
                            </>
                        )
                    )}
                </div>
            </Card>

            {action && (
                <ActionModal
                    title={action.title}
                    busy={busy}
                    onClose={() => setAction(null)}
                    onSubmit={(comment) => {
                        if (action.kind === 'community') {
                            return callApi(`admin/applications/${a.id}/community-review`, { result: action.result, comment });
                        }
                        if (action.kind === 'street') {
                            return callApi(`admin/applications/${a.id}/street-review`, { result: action.result, comment });
                        }
                        if (action.kind === 'reverify') {
                            return callApi(`admin/applications/${a.id}/reverify`, { result: action.result, comment });
                        }
                        if (action.kind === 'appeal') {
                            return callApi(`admin/applications/${a.id}/appeals/${action.followUpId}`, { accepted: action.accepted, comment });
                        }
                    }}
                />
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Card>
                    <h3 className="font-semibold text-slate-800 mb-2">申请资料（提交时快照）</h3>
                    <KeyVal k="申请人" v={a.applicant.name} />
                    <KeyVal k="住址" v={a.payload_snapshot.address} />
                    <KeyVal k="供暖方式" v={a.payload_snapshot.heating_method} />
                    <KeyVal k="居住要求" v={`${a.payload_snapshot.residency_met ? '居民声明符合' : '居民声明不符合'}（${a.rule_snapshot.residency_requirement}）`} />
                    <KeyVal k="收款人" v={`${a.payload_snapshot.payee.name}（${a.payload_snapshot.payee.account}）${a.payload_snapshot.payee.bank ? ' · ' + a.payload_snapshot.payee.bank : ''}`} />
                    {a.payload_snapshot.payee.changed_at && (
                        <div className="text-xs text-amber-600 mt-1">
                            收款人本批内变更于 {a.payload_snapshot.payee.changed_at}，原账户 {a.payload_snapshot.payee.previous?.account}
                        </div>
                    )}
                    <h3 className="font-semibold text-slate-800 mt-4 mb-2">家庭成员</h3>
                    <div className="text-sm space-y-1">
                        {a.household_members?.map((m) => (
                            <div key={m.id} className="flex justify-between"><span>{m.name}</span><span className="text-slate-400">{m.phone || '—'}</span></div>
                        ))}
                    </div>
                </Card>

                <Card>
                    <h3 className="font-semibold text-slate-800 mb-2">
                        资格规则快照
                        <span className="text-xs text-slate-400 ml-2">v{a.rule_snapshot.version}（审核一律以此为准）</span>
                    </h3>
                    <KeyVal k="低温阈值" v={`${a.rule_snapshot.low_temp_threshold}℃`} />
                    <KeyVal k="允许供暖方式" v={a.rule_snapshot.allowed_heating_methods.join('、')} />
                    <KeyVal k="材料有效期" v={`签发后 ${a.rule_snapshot.doc_valid_days} 天`} />
                    <KeyVal k="必备材料" v={a.rule_snapshot.required_documents.join('、')} />
                </Card>
            </div>

            <Card>
                <h3 className="font-semibold text-slate-800 mb-3">材料核验</h3>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs text-slate-400 border-b border-slate-100">
                            <th className="py-2 pr-3">材料</th>
                            <th className="py-2 pr-3">签发日</th>
                            <th className="py-2 pr-3">到期日（按本批 {a.rule_snapshot.doc_valid_days} 天）</th>
                            <th className="py-2">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        {a.documents.map((d) => (
                            <tr key={d.id} className="border-b border-slate-50">
                                <td className="py-2 pr-3">{d.doc_name}</td>
                                <td className="py-2 pr-3">{d.issued_on}</td>
                                <td className="py-2 pr-3">{d.expire_on}</td>
                                <td className="py-2">
                                    <Badge color={d.status === 'expired' ? 'amber' : d.status === 'resubmitted' ? 'sky' : 'emerald'}>
                                        {({ submitted: '有效', expired: '已过期·需补充', resubmitted: '已补交', waived: '免交' })[d.status]}
                                    </Badge>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Card>
                    <h3 className="font-semibold text-slate-800 mb-3">审核留痕（初审/复核分离）</h3>
                    <div className="space-y-3">
                        {a.reviews.map((r, i) => (
                            <div key={r.id ?? i} className="border-l-2 border-slate-200 pl-3 text-sm">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <Badge color={r.level === 'community' ? 'sky' : 'indigo'}>{r.level === 'community' ? '社区初审' : '街道复核'}</Badge>
                                    <span>{({ pass: '通过', reject: '不通过', supplement: '要求补件', reverify: '重新核验通过' })[r.result]}</span>
                                    <span className="text-xs text-slate-400">{r.reviewer}（ID {r.reviewer_id}）· {r.created_at}</span>
                                </div>
                                {r.comment && <p className="text-slate-500 mt-1">{r.comment}</p>}
                                {r.checked_items?.missing_docs?.length > 0 && (
                                    <p className="text-xs text-rose-500 mt-1">缺件：{r.checked_items.missing_docs.map((d) => d.doc_name).join('、')}</p>
                                )}
                                {r.checked_items?.expired_docs?.length > 0 && (
                                    <p className="text-xs text-amber-600 mt-1">过期：{r.checked_items.expired_docs.map((d) => `${d.doc_name}(${d.expire_on})`).join('、')}</p>
                                )}
                            </div>
                        ))}
                        {a.reviews.length === 0 && <p className="text-sm text-slate-400">暂无审核记录</p>}
                    </div>

                    <h3 className="font-semibold text-slate-800 mt-5 mb-2">补件 / 申诉记录</h3>
                    <div className="space-y-2 text-sm">
                        {a.follow_ups.map((f) => (
                            <div key={f.id} className="bg-slate-50 rounded-lg p-3">
                                <div className="flex items-center gap-2">
                                    <Badge color={f.type === 'supplement' ? 'sky' : 'amber'}>{f.type === 'supplement' ? '补件' : '申诉'}</Badge>
                                    <Badge color={f.status === 'accepted' ? 'emerald' : f.status === 'rejected' ? 'rose' : 'slate'}>
                                        {({ pending: '待处理', accepted: '成立/已受理', rejected: '不成立' })[f.status]}
                                    </Badge>
                                    <span className="text-xs text-slate-400">{f.user} · {f.created_at}</span>
                                </div>
                                <p className="text-slate-600 mt-1">{f.reason}</p>
                                {f.handle_comment && <p className="text-xs text-slate-400 mt-1">处理意见：{f.handle_comment}</p>}
                            </div>
                        ))}
                        {a.follow_ups.length === 0 && <p className="text-sm text-slate-400">暂无</p>}
                    </div>
                </Card>

                <Card>
                    <h3 className="font-semibold text-slate-800 mb-3">发放信息</h3>
                    {a.disbursement ? (
                        <>
                            <KeyVal k="状态" v={<Badge color={statusColor(a.disbursement.status)}>{({ listed: '已入清单', paying: '支付处理中', paid: '已到账', failed: '支付失败', frozen: '冻结暂停' })[a.disbursement.status]}</Badge>} />
                            <KeyVal k="金额" v={`${a.disbursement.amount} 元`} />
                            <KeyVal k="收款人" v={a.disbursement.payee_name} />
                            <KeyVal k="账号" v={a.disbursement.payee_account} />
                            <KeyVal k="支付单号" v={a.disbursement.payment_no} />
                            <KeyVal k="回执号" v={a.disbursement.receipt_no} />
                            <KeyVal k="到账时间" v={a.disbursement.paid_at} />
                            <KeyVal k="回执送达次数" v={`${a.disbursement.receipt_count ?? 0} 次（重复回执只入账一次）`} />
                        </>
                    ) : <p className="text-sm text-slate-400">复核通过后自动生成发放记录。</p>}
                </Card>
            </div>

            <Card>
                <h3 className="font-semibold text-slate-800 mb-3">进度时间线</h3>
                <ol className="relative border-l-2 border-slate-100 ml-2 space-y-4">
                    {a.timeline.map((t, i) => (
                        <li key={i} className="ml-4">
                            <span className="absolute -left-[7px] w-3 h-3 rounded-full bg-sky-400 mt-1" />
                            <div className="text-sm text-slate-700">{t.message}</div>
                            <div className="text-xs text-slate-400">{t.created_at}</div>
                        </li>
                    ))}
                </ol>
            </Card>
        </div>
    );
}

function ActionModal({ title, busy, onClose, onSubmit }) {
    const [comment, setComment] = useState('');
    return (
        <Modal title={title} onClose={onClose}
            footer={<>
                <Button variant="secondary" onClick={onClose}>取消</Button>
                <Button onClick={() => onSubmit(comment)} disabled={busy}>{busy ? '处理中…' : '确认'}</Button>
            </>}>
            <Field label="审核意见">
                <textarea className={`${inputCls} mt-1`} rows={3} value={comment} onChange={(e) => setComment(e.target.value)}
                    placeholder="填写核验情况或给居民的说明（将出现在时间线与短信通知中）" />
            </Field>
        </Modal>
    );
}
