import { useEffect, useState } from 'react'
import { api } from '../api'
import { yuan } from '../helpers'

function today(offsetDays = 0) {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return d.toISOString().slice(0, 10)
}

export default function BatchesPage() {
  const [batches, setBatches] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [form, setForm] = useState({
    name: '', level: 'blue',
    started_at: today(0) + 'T08:00',
    subsidy_amount: '500',
    docs: '居民身份证,居住证明,取暖缴费凭证',
    methods: ['coal', 'electric'],
  })

  function load() { api('GET', '/batches').then((r) => setBatches(r.data)) }
  useEffect(load, [])

  async function createBatch() {
    setNotice(''); setError('')
    try {
      await api('POST', '/batches', {
        name: form.name,
        level: form.level,
        started_at: form.started_at,
        subsidy_amount: Math.round(Number(form.subsidy_amount) * 100),
        rules: {
          version: 'manual-' + Date.now(),
          eligible_heating_methods: form.methods,
          required_documents: form.docs.split(/[,，]/).map((s) => s.trim()).filter(Boolean)
            .map((label, i) => ({ type: `doc_${i}`, label })),
        },
      })
      setShowForm(false)
      setNotice('批次已发布，居民申请时将按此规则生成资格快照。')
      load()
    } catch (e) {
      const fields = e.data?.errors
      setError(fields ? Object.values(fields).flat().join('；') : e.message)
    }
  }

  async function endAlert(b) {
    setNotice(''); setError('')
    try {
      await api('POST', `/batches/${b.id}/end-alert`, { late_filing_deadline: today(7) })
      setNotice(`「${b.name}」预警已结束，之后申请标记为补报，仍按本批规则审核（补报截止 ${today(7)}）。`)
      load()
    } catch (e) { setError(e.message) }
  }

  const METHODS = [
    ['coal', '燃煤自采暖'], ['electric', '电采暖'], ['gas', '燃气自采暖'],
    ['central_heating_self_paid', '集中供暖自费'],
  ]

  return (
    <div>
      <h1>低温预警批次与资格规则</h1>
      <p className="muted">规则在批次上定义；居民提交申请的瞬间整体快照存档，之后修改批次规则不影响在途申请。</p>
      {notice && <div className="alert ok">{notice}</div>}
      {error && <div className="alert error">{error}</div>}

      <div className="card">
        <div className="row">
          <h2 style={{ margin: 0 }}>批次列表</h2>
          <span className="spacer" />
          <button className="sm" onClick={() => setShowForm(!showForm)}>
            {showForm ? '收起' : '+ 发布新批次'}
          </button>
        </div>
        <table style={{ marginTop: 10 }}>
          <thead><tr><th>批次</th><th>等级</th><th>时段</th><th>补贴</th><th>状态</th><th>材料</th><th></th></tr></thead>
          <tbody>
            {batches.map((b) => (
              <tr key={b.id}>
                <td>{b.name}<div className="small muted">规则版本 {b.rules.version}</div></td>
                <td>{b.level}</td>
                <td className="small">{b.started_at?.slice(0, 16)}<br />
                  {b.ended_at ? `至 ${b.ended_at.slice(0, 16)}` : '进行中'}</td>
                <td>{yuan(b.subsidy_amount)}/户</td>
                <td>
                  {!b.alert_ended && <span className="badge blue">预警中</span>}
                  {b.alert_ended && (b.accepts_late_filing
                    ? <span className="badge amber">补报中（截止 {b.late_filing_deadline}）</span>
                    : <span className="badge gray">受理已截止</span>)}
                </td>
                <td className="small">{(b.rules.required_documents || []).map((d) => d.label).join('、')}</td>
                <td>
                  {!b.ended_at && <button className="ghost sm" onClick={() => endAlert(b)}>结束预警</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showForm && (
        <div className="card">
          <h2>发布新低温预警批次</h2>
          <div className="grid two">
            <label className="field"><span>批次名称</span>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="例：2026年2月寒潮黄色预警" /></label>
            <label className="field"><span>预警等级</span>
              <select value={form.level} onChange={(e) => setForm({ ...form, level: e.target.value })}>
                <option value="blue">蓝色</option><option value="yellow">黄色</option>
                <option value="orange">橙色</option><option value="red">红色</option>
              </select></label>
            <label className="field"><span>开始时间</span>
              <input type="datetime-local" value={form.started_at}
                onChange={(e) => setForm({ ...form, started_at: e.target.value })} /></label>
            <label className="field"><span>每户补贴（元）</span>
              <input type="number" value={form.subsidy_amount}
                onChange={(e) => setForm({ ...form, subsidy_amount: e.target.value })} /></label>
          </div>
          <label className="field"><span>所需材料（逗号分隔）</span>
            <input value={form.docs} onChange={(e) => setForm({ ...form, docs: e.target.value })} /></label>
          <label className="field"><span>认可供暖方式</span>
            <div className="row">
              {METHODS.map(([v, l]) => (
                <label key={v} className="small row gap" style={{ margin: 0 }}>
                  <input type="checkbox" style={{ width: 'auto' }} checked={form.methods.includes(v)}
                    onChange={() => setForm({
                      ...form,
                      methods: form.methods.includes(v)
                        ? form.methods.filter((x) => x !== v)
                        : [...form.methods, v],
                    })} /> {l}
                </label>
              ))}
            </div>
          </label>
          <div className="row end"><button onClick={createBatch}>发布批次</button></div>
        </div>
      )}
    </div>
  )
}
