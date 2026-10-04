import React, { useState } from 'react';
import { useAuth } from '../auth.jsx';
import { Alert, Button, Field, inputCls } from '../components/ui.jsx';

const DEMO_ACCOUNTS = [
    { label: '居民 张居民', email: 'zhang@demo.local' },
    { label: '居民（配偶，演示一户多人）', email: 'zhang-spouse@demo.local' },
    { label: '社区初审员 李社区', email: 'community@demo.local' },
    { label: '街道复核员 王街道', email: 'street@demo.local' },
    { label: '街道复核员 赵街道（第二人）', email: 'street2@demo.local' },
];

export default function Login() {
    const { login, register } = useAuth();
    const [mode, setMode] = useState('login');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', role: 'resident' });

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    async function submit(e) {
        e.preventDefault();
        setError('');
        setBusy(true);
        try {
            if (mode === 'login') {
                await login(form.email, form.password);
            } else {
                await register(form);
            }
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-sky-100 via-slate-50 to-indigo-100 p-4">
            <div className="w-full max-w-md">
                <div className="text-center mb-6">
                    <div className="text-4xl mb-2">🔥❄️</div>
                    <h1 className="text-2xl font-bold text-slate-800">暖冬补贴系统</h1>
                    <p className="text-sm text-slate-500 mt-1">社区低温天气取暖补贴申领与核验</p>
                </div>

                <div className="bg-white rounded-2xl shadow-xl p-6">
                    <div className="flex bg-slate-100 rounded-lg p-1 mb-5 text-sm font-medium">
                        <button
                            className={`flex-1 py-1.5 rounded-md ${mode === 'login' ? 'bg-white shadow text-sky-700' : 'text-slate-500'}`}
                            onClick={() => setMode('login')}
                        >登录</button>
                        <button
                            className={`flex-1 py-1.5 rounded-md ${mode === 'register' ? 'bg-white shadow text-sky-700' : 'text-slate-500'}`}
                            onClick={() => setMode('register')}
                        >注册</button>
                    </div>

                    <Alert type="error">{error}</Alert>

                    <form onSubmit={submit} className="space-y-3">
                        {mode === 'register' && (
                            <>
                                <Field label="姓名" required>
                                    <input className={inputCls} value={form.name} onChange={set('name')} required />
                                </Field>
                                <Field label="手机号（接收短信通知）">
                                    <input className={inputCls} value={form.phone} onChange={set('phone')} placeholder="139..." />
                                </Field>
                                <Field label="注册身份">
                                    <select className={inputCls} value={form.role} onChange={set('role')}>
                                        <option value="resident">居民</option>
                                        <option value="community">社区初审员</option>
                                        <option value="street">街道复核员</option>
                                    </select>
                                </Field>
                            </>
                        )}
                        <Field label="邮箱" required>
                            <input type="email" className={inputCls} value={form.email} onChange={set('email')} required />
                        </Field>
                        <Field label="密码" required hint={mode === 'login' ? '演示账号密码均为 password' : '至少 6 位'}>
                            <input type="password" className={inputCls} value={form.password} onChange={set('password')} required minLength={6} />
                        </Field>
                        <Button type="submit" className="w-full" disabled={busy}>
                            {busy ? '请稍候…' : mode === 'login' ? '登录' : '注册并进入'}
                        </Button>
                    </form>

                    {mode === 'login' && (
                        <div className="mt-5 pt-4 border-t border-slate-100">
                            <p className="text-xs text-slate-400 mb-2">演示账号（点击填充，密码 password）：</p>
                            <div className="flex flex-wrap gap-1.5">
                                {DEMO_ACCOUNTS.map((a) => (
                                    <button
                                        key={a.email}
                                        type="button"
                                        onClick={() => setForm({ ...form, email: a.email, password: 'password' })}
                                        className="text-xs px-2 py-1 rounded-md bg-slate-100 hover:bg-sky-100 text-slate-600 hover:text-sky-700"
                                    >{a.label}</button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
                <p className="text-center text-xs text-slate-400 mt-4">
                    按低温预警批次受理 · 规则冻结留痕 · 短信/支付服务本地模拟
                </p>
            </div>
        </div>
    );
}
