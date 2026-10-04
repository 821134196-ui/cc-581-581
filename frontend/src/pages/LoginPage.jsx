import { useState } from 'react'
import { useAuth } from '../auth.jsx'

export default function LoginPage() {
  const { login, register } = useAuth()
  const [mode, setMode] = useState('login')
  const [form, setForm] = useState({ name: '', phone: '', password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  function set(k, v) { setForm({ ...form, [k]: v }) }

  async function submit(e) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      if (mode === 'login') {
        await login(form.phone, form.password)
      } else {
        await register({ name: form.name, phone: form.phone, password: form.password })
      }
    } catch (err) {
      const fields = err.data?.errors
      setError(fields ? Object.values(fields).flat().join('；') : err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login-wrap">
      <div className="card">
        <h1>❄️ 社区低温取暖补贴</h1>
        <p className="muted small" style={{ marginTop: 0 }}>
          申领 · 社区初审 · 街道复核 · 补贴发放
        </p>

        <div className="tabs">
          <button className={mode === 'login' ? 'active' : ''} onClick={() => setMode('login')}>登录</button>
          <button className={mode === 'register' ? 'active' : ''} onClick={() => setMode('register')}>居民注册</button>
        </div>

        {error && <div className="alert error">{error}</div>}

        <form onSubmit={submit}>
          {mode === 'register' && (
            <label className="field">
              <span>姓名</span>
              <input value={form.name} onChange={(e) => set('name', e.target.value)} required placeholder="真实姓名" />
            </label>
          )}
          <label className="field">
            <span>手机号</span>
            <input value={form.phone} onChange={(e) => set('phone', e.target.value)} required placeholder="11 位手机号" />
          </label>
          <label className="field">
            <span>密码</span>
            <input type="password" value={form.password} onChange={(e) => set('password', e.target.value)} required />
          </label>
          <button style={{ width: '100%' }} disabled={busy}>
            {busy ? '请稍候…' : mode === 'login' ? '登录' : '注册并登录'}
          </button>
        </form>

        <p className="muted small" style={{ marginBottom: 0 }}>
          演示账号（密码均为 password）：<br />
          居民 13800000001 / 13800000002　社区 13900000001　街道 13700000001
        </p>
      </div>
    </div>
  )
}
