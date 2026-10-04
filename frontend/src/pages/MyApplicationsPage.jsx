import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { STATUS_BADGE, yuan } from '../helpers'

export default function MyApplicationsPage() {
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api('GET', '/my/applications')
      .then((r) => setApps(r.data))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="empty">加载中…</div>

  return (
    <div>
      <h1>我的申请</h1>
      <p className="muted">查看是否符合条件、缺哪些材料、审核结果与到账进度。</p>

      {apps.length === 0 && (
        <div className="card empty">
          还没有申请记录。<Link to="/apply">去申请本批低温取暖补贴 →</Link>
        </div>
      )}

      {apps.map((a) => (
        <div className="card" key={a.id}>
          <div className="row">
            <strong>申请 #{a.id}</strong>
            <span className={`badge ${STATUS_BADGE[a.status] || 'gray'}`}>{a.status_text}</span>
            {a.is_late && <span className="badge amber">预警后补报</span>}
            {a.frozen && <span className="badge red">已冻结</span>}
            {a.merged_into_id && <span className="badge gray">已并入 #{a.merged_into_id}</span>}
            <span className="spacer" />
            <Link className="btn ghost sm" to={`/applications/${a.id}`}>查看详情</Link>
          </div>
          <dl className="kv" style={{ marginTop: 10 }}>
            <dt>预警批次</dt><dd>{a.batch_name}{a.is_late && '（按本批规则审核）'}</dd>
            <dt>住址</dt><dd>{a.address}</dd>
            <dt>家庭成员</dt><dd>{(a.members || []).join('、')}</dd>
            <dt>到账进度</dt>
            <dd>
              {a.payment
                ? <span className={a.payment.status === 'paid' ? '' : 'muted'}>
                    {a.payment.status === 'paid'
                      ? <>✅ 已到账 {yuan(a.payment.amount)}（{a.payment.paid_at}）</>
                      : <>⏳ 已入发放清单，待打款 {yuan(a.payment.amount)}
                          {a.payment.transfer_ref && `（转账单号 ${a.payment.transfer_ref}，等待银行回执）`}</>}
                  </span>
                : <span className="muted">尚未进入发放清单</span>}
            </dd>
          </dl>
        </div>
      ))}
    </div>
  )
}
