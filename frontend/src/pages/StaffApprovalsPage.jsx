import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth.jsx'

export default function StaffApprovalsPage() {
  const { user } = useAuth()
  const [tab, setTab] = useState('payee')
  const [payees, setPayees] = useState([])
  const [appeals, setAppeals] = useState([])
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  function load() {
    if (user.role === 'street') api('GET', '/payee-changes/pending').then((r) => setPayees(r.data))
    api('GET', '/appeals/pending').then((r) => setAppeals(r.data))
  }
  useEffect(load, [user.role])

  async function verify(id, pass) {
    setNotice(''); setError('')
    try {
      await api('POST', `/payee-changes/${id}/verify`, {
        pass, note: pass ? '身份与账户信息核对一致' : '无法核实账户归属',
      })
      setNotice(pass ? '收款人变更已核验通过并生效，可继续发放。' : '变更已驳回，维持原收款人。')
      load()
    } catch (e) { setError(e.message) }
  }

  async function handleAppeal(id, accepted) {
    setNotice(''); setError('')
    const reply = accepted ? '申诉成立，申请按原批次规则重新审核。' : '申诉材料不足，维持原审核结论。'
    try {
      await api('POST', `/appeals/${id}/handle`, { accepted, reply })
      setNotice(accepted ? '申诉成立，申请已退回审核队列（沿原申请、原批次）。' : '申诉已驳回。')
      load()
    } catch (e) { setError(e.message) }
  }

  return (
    <div>
      <h1>待办处理</h1>
      {error && <div className="alert error">{error}</div>}
      {notice && <div className="alert ok">{notice}</div>}

      <div className="tabs">
        {user.role === 'street' && (
          <button className={tab === 'payee' ? 'active' : ''} onClick={() => setTab('payee')}>
            收款人变更核验（{payees.length}）
          </button>
        )}
        <button className={tab === 'appeals' ? 'active' : ''} onClick={() => setTab('appeals')}>
          居民申诉（{appeals.length}）
        </button>
      </div>

      {tab === 'payee' && user.role === 'street' && (
        <div className="card" style={{ padding: 0 }}>
          <table>
            <thead><tr><th>申请</th><th>住址</th><th>新收款人</th><th>提交时间</th><th>操作</th></tr></thead>
            <tbody>
              {payees.map((c) => (
                <tr key={c.id}>
                  <td><Link to={`/applications/${c.application_id}`}>#{c.application_id}</Link></td>
                  <td>{c.address}</td>
                  <td>{c.payee_name} <span className="muted small">{c.payee_bank} {c.payee_account}</span></td>
                  <td className="small muted">{c.at}</td>
                  <td className="row gap">
                    <button className="ok sm" onClick={() => verify(c.id, true)}>核验通过</button>
                    <button className="danger sm" onClick={() => verify(c.id, false)}>驳回</button>
                  </td>
                </tr>
              ))}
              {payees.length === 0 && <tr><td colSpan={5} className="empty">暂无待核验的收款人变更。</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'appeals' && (
        <div className="card" style={{ padding: 0 }}>
          <table>
            <thead><tr><th>申请</th><th>住址</th><th>居民</th><th>申诉理由</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
              {appeals.map((p) => (
                <tr key={p.id}>
                  <td><Link to={`/applications/${p.application_id}`}>#{p.application_id}</Link></td>
                  <td>{p.address}</td>
                  <td>{p.resident}</td>
                  <td>{p.reason}</td>
                  <td className="small muted">{p.at}</td>
                  <td className="row gap">
                    <button className="ok sm" onClick={() => handleAppeal(p.id, true)}>申诉成立</button>
                    <button className="danger sm" onClick={() => handleAppeal(p.id, false)}>驳回</button>
                  </td>
                </tr>
              ))}
              {appeals.length === 0 && <tr><td colSpan={6} className="empty">暂无待处理申诉。</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
