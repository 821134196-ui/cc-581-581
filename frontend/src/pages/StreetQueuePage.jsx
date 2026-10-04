import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'

export default function StreetQueuePage() {
  const [items, setItems] = useState([])
  const [busyId, setBusyId] = useState(null)
  const [error, setError] = useState('')

  function load() {
    api('GET', '/street/queue').then((r) => setItems(r.data))
  }
  useEffect(load, [])

  async function decide(id, decision) {
    setError('')
    setBusyId(id)
    try {
      await api('POST', `/applications/${id}/street-review`, { decision })
      load()
    } catch (e) {
      const fields = e.data?.errors
      setError(`申请 #${id}：` + (fields ? Object.values(fields).flat().join('；') : e.message))
    } finally {
      setBusyId(null)
    }
  }

  async function freeze(id, frozen) {
    setError('')
    try {
      await api('POST', `/applications/${id}/freeze`, { frozen, reason: frozen ? '收款人信息待核实' : null })
      load()
    } catch (e) { setError(e.message) }
  }

  return (
    <div>
      <h1>街道复核队列</h1>
      <p className="muted">
        复核通过即形成本批发放清单。系统会自动拦截：本人做过初审的申请不能由本人复核；收款人变更未核验、已冻结的申请不能通过。
      </p>
      {error && <div className="alert error">{error}</div>}
      {items.length === 0 && <div className="card empty">暂无待复核申请。</div>}

      {items.map((a) => (
        <div className="card" key={a.id}>
          <div className="row">
            <strong>申请 #{a.id}</strong>
            {a.is_late && <span className="badge amber">预警后补报</span>}
            {!a.payee_verified && <span className="badge amber">收款人待重新核验</span>}
            <span className="spacer" />
            <Link className="btn ghost sm" to={`/applications/${a.id}`}>完整资料</Link>
          </div>
          <dl className="kv" style={{ marginTop: 8 }}>
            <dt>申请人/成员</dt><dd>{a.applicant}；{a.members.join('、')}</dd>
            <dt>住址</dt><dd>{a.address}</dd>
            <dt>收款人</dt><dd>{a.payee} {a.payee_verified ? '✅已核验' : '⚠️待核验'}</dd>
          </dl>
          {a.blocked_for_me && (
            <div className="alert warn">
              {a.block_reason || '您已对该申请做过社区初审，按规定不能再复核同一份申请，请由其他街道人员处理。'}
            </div>
          )}
          <div className="row">
            <button className="ok sm" disabled={busyId === a.id || a.blocked_for_me}
              onClick={() => decide(a.id, 'approve')}>复核通过并入清单</button>
            <button className="warn sm" disabled={busyId === a.id} onClick={() => decide(a.id, 'return_supplement')}>退回补件</button>
            <button className="danger sm" disabled={busyId === a.id} onClick={() => decide(a.id, 'reject')}>复核不通过</button>
            <span className="spacer" />
            <button className="ghost sm" onClick={() => freeze(a.id, true)}>冻结申请</button>
          </div>
        </div>
      ))}
    </div>
  )
}
