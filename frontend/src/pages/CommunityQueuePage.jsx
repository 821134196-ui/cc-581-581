import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { DOC_STATUS } from '../helpers'

export default function CommunityQueuePage() {
  const [items, setItems] = useState([])
  const [busyId, setBusyId] = useState(null)
  const [error, setError] = useState('')

  function load() {
    api('GET', '/community/queue').then((r) => setItems(r.data))
  }
  useEffect(load, [])

  async function decide(id, decision) {
    setError('')
    setBusyId(id)
    try {
      await api('POST', `/applications/${id}/community-review`, { decision })
      load()
    } catch (e) {
      const fields = e.data?.errors
      setError(`申请 #${id}：` + (fields ? Object.values(fields).flat().join('；') : e.message))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div>
      <h1>社区初审队列</h1>
      <p className="muted">按申请保存的本批规则核验资格与材料；通过后上报街道复核。</p>
      {error && <div className="alert error">{error}</div>}
      {items.length === 0 && <div className="card empty">暂无待初审申请。</div>}

      {items.map((a) => (
        <div className="card" key={a.id}>
          <div className="row">
            <strong>申请 #{a.id}</strong>
            {a.is_late && <span className="badge amber">预警后补报</span>}
            <span className="spacer" />
            <Link className="btn ghost sm" to={`/applications/${a.id}`}>完整资料</Link>
          </div>
          <dl className="kv" style={{ marginTop: 8 }}>
            <dt>申请人/成员</dt><dd>{a.applicant}；{a.members.join('、')}</dd>
            <dt>住址</dt><dd>{a.address}</dd>
            <dt>批次</dt><dd>{a.batch_name}</dd>
            <dt>供暖方式</dt><dd>{a.heating_method}</dd>
            <dt>收款人</dt><dd>{a.payee}</dd>
          </dl>
          <div style={{ margin: '8px 0' }}>
            {a.documents.map((d, i) => (
              <span key={i} className={`badge ${DOC_STATUS[d.status]?.[0] || 'gray'}`} style={{ marginRight: 6 }}>
                {d.label}·{DOC_STATUS[d.status]?.[1] || d.status}
              </span>
            ))}
          </div>
          <div className="row">
            <button className="ok sm" disabled={busyId === a.id} onClick={() => decide(a.id, 'approve')}>初审通过</button>
            <button className="warn sm" disabled={busyId === a.id} onClick={() => decide(a.id, 'return_supplement')}>退回补件</button>
            <button className="danger sm" disabled={busyId === a.id} onClick={() => decide(a.id, 'reject')}>初审不通过</button>
          </div>
        </div>
      ))}
    </div>
  )
}
