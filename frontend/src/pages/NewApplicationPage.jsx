import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { HEATING_LABELS } from '../helpers'

const EMPTY_MEMBER = { name: '', id_number: '', phone: '' }

export default function NewApplicationPage() {
  const nav = useNavigate()
  const [batches, setBatches] = useState([])
  const [form, setForm] = useState({
    alert_batch_id: '',
    address: '',
    heating_method: 'coal',
    payee_name: '',
    payee_account: '',
    payee_bank: '',
    note: '',
  })
  const [members, setMembers] = useState([{ ...EMPTY_MEMBER }])
  const [error, setError] = useState('')
  const [mergeHint, setMergeHint] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(null)

  useEffect(() => {
    api('GET', '/batches').then((r) => {
      setBatches(r.data)
      const first = r.data[0]
      if (first) setForm((f) => ({ ...f, alert_batch_id: String(first.id) }))
    })
  }, [])

  const batch = batches.find((b) => String(b.id) === String(form.alert_batch_id))

  function set(k, v) { setForm({ ...form, [k]: v }) }
  function setMember(i, k, v) {
    setMembers(members.map((m, idx) => (idx === i ? { ...m, [k]: v } : m)))
  }

  async function submit(mergeApplicationId = null) {
    setError('')
    setBusy(true)
    try {
      const r = await api('POST', '/applications', {
        ...form,
        alert_batch_id: Number(form.alert_batch_id),
        members: members.filter((m) => m.name && m.id_number),
        ...(mergeApplicationId ? { merge_application_id: mergeApplicationId } : {}),
      })
      setDone(r)
      setTimeout(() => nav(`/applications/${r.application_id}`), 900)
    } catch (err) {
      if (err.status === 409 && err.data?.error_code === 'DUPLICATE_IN_HOUSEHOLD') {
        setMergeHint(err.data)
      } else {
        const fields = err.data?.errors
        setError(fields ? Object.values(fields).flat().join('；') : err.message)
      }
    } finally {
      setBusy(false)
    }
  }

  if (done) {
    return <div className="card"><div className="alert ok">
      {done.merged ? '已并入同户原申请，正在跳转…' : '申请已提交，正在跳转…'}
    </div></div>
  }

  return (
    <div>
      <h1>申请低温取暖补贴</h1>
      <p className="muted">选择本批低温预警提交申请；规则与资料会在提交时一并存档。</p>

      {error && <div className="alert error">{error}</div>}
      {mergeHint && (
        <div className="alert warn">
          <strong>检测到同户重复申请：</strong>{mergeHint.message}
          <div className="small" style={{ marginTop: 6 }}>
            已有申请 #{mergeHint.existing_applications[0].application_id}
            （申请人：{mergeHint.existing_applications[0].applicant}，
            成员：{(mergeHint.existing_applications[0].members || []).join('、')}）。
            补贴按户只发一次，请与家人确认。
          </div>
          <div className="row" style={{ marginTop: 10 }}>
            <button className="ok sm" disabled={busy}
              onClick={() => submit(mergeHint.existing_applications[0].application_id)}>
              确认并入申请 #{mergeHint.existing_applications[0].application_id}
            </button>
            <button className="ghost sm" onClick={() => setMergeHint(null)}>取消，回去核对住址</button>
          </div>
        </div>
      )}

      <div className="card">
        <h2>① 选择低温预警批次</h2>
        <label className="field">
          <span>预警批次（按本批规则审核，不会自动更换批次）</span>
          <select value={form.alert_batch_id} onChange={(e) => set('alert_batch_id', e.target.value)}>
            {batches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}{b.alert_ended ? '（预警已结束' + (b.accepts_late_filing ? '，补报中' : '，补报已截止') + '）' : '（预警进行中）'}
              </option>
            ))}
          </select>
        </label>
        {batch && (
          <div className="small muted">
            {batch.alert_ended && (
              <div className="alert warn" style={{ padding: '8px 12px' }}>
                预警已于 {batch.ended_at} 结束；
                {batch.accepts_late_filing
                  ? <>补报截止 {batch.late_filing_deadline}，补报仍<strong>按本批规则</strong>审核。</>
                  : <>已超过补报截止日期 {batch.late_filing_deadline}，本批无法再申请。</>}
              </div>
            )}
            补贴标准：<strong>¥{(batch.subsidy_amount / 100).toFixed(2)}</strong> / 户；
            认可供暖方式：{(batch.rules.eligible_heating_methods || []).map((m) => HEATING_LABELS[m] || m).join('、')}
          </div>
        )}
      </div>

      <div className="card">
        <h2>② 住址与家庭成员</h2>
        <label className="field">
          <span>家庭住址（同一住址视为一户）</span>
          <input value={form.address} onChange={(e) => set('address', e.target.value)}
            placeholder="例：幸福里小区 3 栋 201 室" />
        </label>

        <span>享受补贴的家庭成员（第一位为申请人本人）</span>
        {members.map((m, i) => (
          <div key={i} className="row" style={{ marginBottom: 8 }}>
            <input style={{ flex: 1 }} placeholder="姓名" value={m.name}
              onChange={(e) => setMember(i, 'name', e.target.value)} />
            <input style={{ flex: 1.4 }} placeholder="证件号" value={m.id_number}
              onChange={(e) => setMember(i, 'id_number', e.target.value)} />
            <input style={{ flex: 1 }} placeholder="手机号（选填）" value={m.phone}
              onChange={(e) => setMember(i, 'phone', e.target.value)} />
            {members.length > 1 && (
              <button type="button" className="ghost sm"
                onClick={() => setMembers(members.filter((_, idx) => idx !== i))}>删除</button>
            )}
          </div>
        ))}
        <button type="button" className="ghost sm"
          onClick={() => setMembers([...members, { ...EMPTY_MEMBER }])}>
          + 添加家庭成员
        </button>
        <p className="muted small">提示：若家人已在同批次提交申请，提交时会提示并入该申请，补贴按户只发一次。</p>
      </div>

      <div className="card">
        <h2>③ 供暖方式与收款信息</h2>
        <label className="field">
          <span>供暖方式</span>
          <select value={form.heating_method} onChange={(e) => set('heating_method', e.target.value)}>
            {Object.entries(HEATING_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            <option value="other">其他（不在本批范围，将无法通过资格判断）</option>
          </select>
        </label>
        <div className="grid two">
          <label className="field"><span>收款人姓名</span>
            <input value={form.payee_name} onChange={(e) => set('payee_name', e.target.value)} /></label>
          <label className="field"><span>收款账号</span>
            <input value={form.payee_account} onChange={(e) => set('payee_account', e.target.value)} /></label>
        </div>
        <label className="field"><span>开户银行（选填）</span>
          <input value={form.payee_bank} onChange={(e) => set('payee_bank', e.target.value)} /></label>
        <label className="field"><span>备注（选填）</span>
          <textarea value={form.note} onChange={(e) => set('note', e.target.value)} /></label>
      </div>

      <div className="row end">
        <button disabled={busy || (batch && !batch.accepts_late_filing)}
          onClick={() => submit()}>
          {busy ? '提交中…' : '提交申请'}
        </button>
      </div>
    </div>
  )
}
