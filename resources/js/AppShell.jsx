import React from 'react';
import { useAuth } from './auth.jsx';
import Login from './pages/Login.jsx';
import ResidentApp from './pages/resident/ResidentApp.jsx';
import AdminApp from './pages/admin/AdminApp.jsx';

export default function AppShell() {
    const { user, logout } = useAuth();

    if (!user) {
        return <Login />;
    }

    return (
        <div className="min-h-screen">
            <header className="bg-white border-b border-slate-200 sticky top-0 z-20">
                <div className="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <span className="text-xl">🔥</span>
                        <span className="font-bold text-slate-800">暖冬补贴系统</span>
                        <span className="text-xs text-slate-400 ml-2">
                            {user.role === 'resident' ? '居民端' : user.role === 'community' ? '社区工作台' : '街道工作台'}
                        </span>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="text-slate-500">{user.name} · {user.role_label}</span>
                        <button onClick={logout} className="text-slate-400 hover:text-rose-500">退出</button>
                    </div>
                </div>
            </header>

            <main className="max-w-6xl mx-auto px-4 py-6">
                {user.role === 'resident' ? <ResidentApp /> : <AdminApp />}
            </main>
        </div>
    );
}
