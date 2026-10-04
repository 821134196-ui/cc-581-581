import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth.jsx'
import { DOC_STATUS, STATUS_BADGE, yuan } from '../helpers'

export default function ApplicationDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [app, setApp] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const reload = useCallback(() => {
    api('GET', `/applications/${id}`).then((r) => setApp(r.data))
  }, [id])

  useEffect(() => { reload() }, [reload])

  if (!app) return <div className="empty">加载中…</div>

  const isOwner = app.status !== undefined && user.id !== undefined
  const elig = app.eligibility
  const expiredTypes = new Set(app.documents.filter((d) => d.status === 'expired').map((d) => d.doc_type))
  const missingTypes = new Set(app.documents.filter((d) => d.status === 'required').map((d) => d.doc_type))

  async function guard(fn) {
    setError(''); setNotice('')
    try { await fn(); reload() }
    catch (e) {
      const fields = e.data?.errors
      setError(fields ? Object.values(fields).flat().join('；') : e.message)
    }
  }

  return (
    <div>
      <div className="row">
        <h1 style={{ margin: 0 }}>申请 #{app.id}</h1>
        <span className={`badge ${STATUS_BADGE[app.status] || 'gray'}`}>{app.status_text}</span>
        {app.is_late && <span className="badge amber">预警后补报（按本批规则）</span>}
        {app.frozen && <span className="badge red">已冻结</span>}
      </div>
      <p className="muted">{app.batch_name} · {app.address}</p>

      {error && <div className="alert error">{error}</div>}
      {notice && <div className="alert ok">{notice}</div>}

      <div className="grid two">
        <div className="card">
          <h2>资格判断</h2>
          {elig.eligible
            ? <div className="alert ok">✅ 符合本批资格条件</div>
            : <div className="alert warn">⚠️ 当前暂不符合，请按提示处理后重新审核</div>}
          <ul style={{ margin: '6px 0 0', paddingLeft: 20 }}>
            <li className={elig.heating_ok ? '' : 'muted'}>
              供暖方式「{app.heating_method}」{elig.heating_ok ? '在补贴范围内' : '不在本批补贴范围'}
            </li>
            {elig.missing_documents.map((d) => (
              <li key={d.type} style={{ color: 'var(--warn)' }}>缺少材料：{d.label}</li>
            ))}
            {elig.expired_documents.map((d) => (
              <li key={d.type} style={{ color: 'var(--bad)' }}>材料已过期，请重新提交：{d.label}</li>
            ))}
          </ul>
          <p className="muted small" style={{ marginBottom: 0 }}>
            依据申请时保存的规则快照（{elig.rule_version}）判断，批次规则后续调整不影响本申请。
          </p>
        </div>

        <div className="card">
          <h2>到账进度</h2>
          {app.payment ? (
            <dl className="kv">
              <dt>清单金额</dt><dd>{yuan(app.payment.amount)}</dd>
              <dt>状态</dt><dd>{app.payment.status === 'paid'
                ? <span className="badge green">已到账</span>
                : <span className="badge blue">待打款</span>}</dd>
              {app.payment.transfer_ref && <><dt>转账单号</dt><dd className="small">{app.payment.transfer_ref}</dd></>}
              {app.payment.paid_at && <><dt>到账时间</dt><dd>{app.payment.paid_at}</dd></>}
            </dl>
          ) : <p className="muted" style={{ margin: 0 }}>复核通过后进入本批发放清单。</p>}
          <h2 style={{ marginTop: 14 }}>收款人</h2>
          <dl className="kv">
            <dt>姓名</dt><dd>{app.payee.name}
              {app.payee.verified
                ? <span className="badge green" style={{ marginLeft: 8 }}>已核验</span>
                : <span className="badge amber" style={{ marginLeft: 8 }}>变更待核验·暂不打款</span>}</dd>
            <dt>账号</dt><dd>{app.payee.account}</dd>
            {app.payee.bank && <><dt>开户行</dt><dd>{app.payee.bank}</dd></>}
          </dl>
          {(user.role === 'resident') && (
            <PayeeChangeForm app={app} onDone={guard} reload={reload} />
          )}
        </div>
      </div>

      <div className="card">
        <h2>申请材料{expiredTypes.size > 0 && <span className="badge red" style={{ marginLeft: 8 }}>{expiredTypes.size} 份过期</span>}</h2>
        {app.documents.map((d) => (
          <div className="doc-row" key={d.doc_type}>
            <div className="grow">
              {d.label}
              {d.expires_at && <span className="muted small">（有效期至 {d.expires_at}）</span>}
            </div>
            <span className={`badge ${DOC_STATUS[d.status]?.[0] || 'gray'}`}>
              {DOC_STATUS[d.status]?.[1] || d.status}
            </span>
            {user.role === 'resident' && d.status !== 'submitted' && (
              <DocumentUploadForm appId={app.id} docType={d.doc_type}
                expired={d.status === 'expired'} onDone={guard} />
            )}
          </div>
        ))}
        <p className="muted small">补件始终沿本申请跟进，不会产生新申请，也不会更换批次。</p>
      </div>

      {user.role === 'resident' && (
        <div className="card">
          <h2>申诉</h2>
          <AppealForm app={app} onDone={guard} />
          {app.appeals.length > 0 && (
            <table style={{ marginTop: 10 }}>
              <thead><tr><th>申诉理由</th><th>状态</th><th>回复</th><th>时间</th></tr></thead>
              <tbody>
                {app.appeals.map((p, i) => (
                  <tr key={i}>
                    <td>{p.reason}</td>
                    <td>{p.status === 'pending' ? '待处理' : p.status === 'accepted' ? '成立' : '不成立'}</td>
                    <td>{p.reply || '—'}</td>
                    <td className="small muted">{p.at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      <div className="card">
        <h2>审核记录</h2>
        {app.reviews.length === 0 ? <p className="muted" style={{ margin: 0 }}>暂无审核记录。</p> : (
          <table>
            <thead><tr><th>环节</th><th>审核人</th><th>结论</th><th>意见</th><th>时间</th></tr></thead>
            <tbody>
              {app.reviews.map((r, i) => (
                <tr key={i}>
                  <td>{r.stage === 'community' ? '社区初审' : '街道复核'}</td>
                  <td>{r.reviewer}</td>
                  <td>{r.decision === 'approve' ? '通过' : r.decision === 'reject' ? '不通过' : '退回补件'}</td>
                  <td>{r.note || '—'}</td>
                  <td className="small muted">{r.at}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="card">
        <h2>办理进度时间线</h2>
        <ul className="timeline">
          {app.timeline.map((e, i) => (
            <li key={i}>
              <div>{e.message}</div>
              <div className="t-time">{e.at}</div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function DocumentUploadForm({ appId, docType, expired, onDone }) {
  const [expiresAt, setExpiresAt] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <div className="row">
      <input type="date" style={{ width: 150 }} value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)}
        title="材料有效期（选填，早于今天将判定为过期）" />
      <button className="sm" disabled={busy} onClick={() => onDone(async () => {
        setBusy(true)
        try {
          await api('POST', `/applications/${appId}/documents`, {
            doc_type: docType,
            expires_at: expiresAt || null,
          })
        } finally { setBusy(false) }
      })}>{expired ? '重新补交' : '补交'}</button>
    </div>
  )
}

function AppealForm({ app, onDone }) {
  const [reason, setReason] = useState('')
  const canAppeal = ['community_rejected', 'street_rejected', 'supplementing'].includes(app.status)
  return (
    <div>
      {!canAppeal && <p className="muted small" style={{ marginTop: 0 }}>
        审核不通过或被退回补件时，可在此沿原申请提交申诉。</p>}
      <div className="row">
        <input style={{ flex: 1 }} placeholder="申诉理由（如：低保证明正在年审，申请宽限补件）"
          value={reason} onChange={(e) => setReason(e.target.value)} />
        <button disabled={!reason} onClick={() => onDone(async () => {
          await api('POST', `/applications/${app.id}/appeals`, { reason })
          setReason('')
        })}>提交申诉</button>
      </div>
    </div>
  )
}

function PayeeChangeForm({ app, onDone }) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ payee_name: '', payee_account: '', payee_bank: '' })
  if (['paid', 'merged'].includes(app.status)) return null
  if (!open) return <button className="ghost sm" onClick={() => setOpen(true)}>变更收款人</button>
  return (
    <div style={{ marginTop: 10 }}>
      <div className="alert info small">
        {app.frozen || ['approved', 'pending_street'].includes(app.status)
          ? '申请已冻结或进入复核/发放环节，变更收款人需街道重新核验，核验通过前不会打款。'
          : '当前环节可直接变更收款人。'}
      </div>
      <div className="grid two">
        <input placeholder="新收款人姓名" value={form.payee_name}
          onChange={(e) => setForm({ ...form, payee_name: e.target.value })} />
        <input placeholder="新收款账号" value={form.payee_account}
          onChange={(e) => setForm({ ...form, payee_account: e.target.value })} />
      </div>
      <div className="row" style={{ marginTop: 8 }}>
        <input placeholder="开户行（选填）" value={form.payee_bank}
          onChange={(e) => setForm({ ...form, payee_bank: e.target.value })} />
        <button className="sm" onClick={() => onDone(async () => {
          await api('POST', `/applications/${app.id}/payee-change`, form)
          setOpen(false)
        })}>提交变更</button>
        <button className="ghost sm" onClick={() => setOpen(false)}>取消</button>
      </div>
    </div>
  )
}
