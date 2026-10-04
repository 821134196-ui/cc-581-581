import { useEffect, useState } from 'react'
import { api } from '../api'
import { useAuth } from '../auth.jsx'

const TAG_LABEL = {
  submitted: '受理通知', merge: '合并提示', supplement_done: '补件完成',
  doc_expired: '材料过期', community_approve: '初审通过',
  community_return_supplement: '退回补件', community_reject: '初审不通过',
  street_approve: '复核通过', street_return_supplement: '复核退件',
  street_reject: '复核不通过', frozen: '冻结', unfrozen: '解冻',
  payee_change: '收款人变更', payee_verified: '变更核验',
  appeal_ok: '申诉成立', appeal_no: '申诉驳回', paid: '到账通知',
}

export default function SmsInboxPage() {
  const { user } = useAuth()
  const [messages, setMessages] = useState([])

  useEffect(() => {
    api('GET', '/sms').then((r) => setMessages(r.data))
  }, [])

  return (
    <div>
      <h1>短信收件箱</h1>
      <p className="muted">
        本地模拟短信通道：系统通知全部发送到这里{user.role === 'resident' ? '，仅展示发送给本人手机号的短信' : '（工作人员视角可见全部）'}。
      </p>
      {messages.length === 0 && <div className="card empty">暂无短信。</div>}
      {messages.map((m) => (
        <div className="card" key={m.id}>
          <div className="row">
            <span className="badge blue">{TAG_LABEL[m.tag] || m.tag || '通知'}</span>
            {user.role !== 'resident' && <span className="muted small">→ {m.phone}</span>}
            <span className="spacer" />
            <span className="muted small">{m.at}</span>
          </div>
          <div style={{ marginTop: 6 }}>{m.content}</div>
        </div>
      ))}
    </div>
  )
}
