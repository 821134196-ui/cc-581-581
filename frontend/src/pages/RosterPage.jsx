import { useEffect, useState } from 'react'
import { api } from '../api'
import { yuan } from '../helpers'

export default function RosterPage() {
  const [batches, setBatches] = useState([])
  const [batchId, setBatchId] = useState('')
  const [rows, setRows] = useState([])
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api('GET', '/batches').then((r) => {
      setBatches(r.data)
      if (r.data[0]) { setBatchId(String(r.data[0].id)); load(String(r.data[0].id)) }
    })
  }, [])

  function load(id) {
    api('GET', `/batches/${id}/roster`).then((r) => setRows(r.data))
  }

  async function payout(id) {
    setNotice(''); setError('')
    try {
      const r = await api('POST', `/payments/${id}/payout`, {})
      setNotice(`转账已发起，单号：${r.data.transfer_ref}，等待银行回执到账。`)
      load(batchId)
    } catch (e) { setError(e.message) }
  }

  /** 模拟银行送达成功回执；再点一次 = 重复送达，验证只记一次到账 */
  async function receipt(id, same = false) {
    setNotice(''); setError('')
    try {
      const body = same ? { receipt_no: `RCPT-FIXED-${id}` } : {}
      const r = await api('POST', `/payments/${id}/simulate-receipt`, body)
      setNotice(r.duplicated
        ? '检测到重复回执，已忽略——没有重复记到账。'
        : '银行成功回执已送达，到账已记录，居民会收到到账短信。')
      load(batchId)
    } catch (e) { setError(e.message) }
  }

  const total = rows.reduce((s, r) => s + r.amount, 0)

  return (
    <div>
      <h1>本批发放清单</h1>
      <p className="muted">街道复核通过的申请自动入清单，每户一笔；支付回执重复送达只记一次到账。</p>

      <div className="card row">
        <strong>批次：</strong>
        <select style={{ width: 320 }} value={batchId} onChange={(e) => { setBatchId(e.target.value); load(e.target.value) }}>
          {batches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        <span className="spacer" />
        <span>共 {rows.length} 户，合计 <strong>{yuan(total)}</strong></span>
      </div>

      {notice && <div className="alert ok">{notice}</div>}
      {error && <div className="alert error">{error}</div>}

      <div className="card" style={{ padding: 0 }}>
        <table>
          <thead>
            <tr>
              <th>#</th><th>住址 / 申请人</th><th>家庭成员</th><th>收款人</th>
              <th>金额</th><th>状态</th><th>操作</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.payment_id}>
                <td>{r.application_id}</td>
                <td>{r.address}<div className="small muted">{r.applicant}</div></td>
                <td className="small">{r.members.join('、')}</td>
                <td className="small">{r.payee}<br />{r.account}</td>
                <td>{yuan(r.amount)}</td>
                <td>
                  {r.status === 'paid'
                    ? <span className="badge green">已到账 {r.paid_at?.slice(5, 16)}</span>
                    : r.transfer_ref
                      ? <span className="badge blue">转账中·待回执</span>
                      : <span className="badge gray">待发起转账</span>}
                  {r.frozen && <div className="badge red" style={{ marginTop: 4 }}>冻结中不发</div>}
                  {!r.payee_verified && <div className="badge amber" style={{ marginTop: 4 }}>收款人未核验</div>}
                </td>
                <td>
                  {r.status === 'paid' ? (
                    <button className="ghost sm" title="模拟银行把同一张回执再送一次"
                      onClick={() => receipt(r.payment_id, true)}>重复送达回执</button>
                  ) : r.transfer_ref ? (
                    <button className="sm ok" onClick={() => receipt(r.payment_id)}>模拟成功回执</button>
                  ) : (
                    <button className="sm" disabled={r.frozen || !r.payee_verified}
                      onClick={() => payout(r.payment_id)}>发起转账</button>
                  )}
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={7} className="empty">本批暂无入清单申请。</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
