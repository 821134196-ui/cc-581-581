import React from 'react';

export function Card({ children, className = '' }) {
    return <div className={`bg-white rounded-xl shadow-sm border border-slate-200 p-5 ${className}`}>{children}</div>;
}

export function Button({ children, variant = 'primary', className = '', ...props }) {
    const styles = {
        primary: 'bg-sky-600 hover:bg-sky-700 text-white',
        secondary: 'bg-slate-100 hover:bg-slate-200 text-slate-700',
        danger: 'bg-rose-600 hover:bg-rose-700 text-white',
        success: 'bg-emerald-600 hover:bg-emerald-700 text-white',
        warning: 'bg-amber-500 hover:bg-amber-600 text-white',
        ghost: 'bg-transparent hover:bg-slate-100 text-slate-600',
    };
    return (
        <button
            className={`px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed ${styles[variant]} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

export function Badge({ color = 'slate', children }) {
    const colors = {
        slate: 'bg-slate-100 text-slate-700',
        sky: 'bg-sky-100 text-sky-700',
        amber: 'bg-amber-100 text-amber-800',
        emerald: 'bg-emerald-100 text-emerald-800',
        rose: 'bg-rose-100 text-rose-700',
        indigo: 'bg-indigo-100 text-indigo-700',
    };
    return <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${colors[color]}`}>{children}</span>;
}

export function statusColor(status) {
    return {
        draft: 'slate',
        submitted: 'sky',
        street_pending: 'indigo',
        supplement_requested: 'amber',
        community_rejected: 'rose',
        rejected: 'rose',
        appealing: 'amber',
        frozen: 'amber',
        approved: 'sky',
        disbursed: 'emerald',
        listed: 'sky',
        paying: 'indigo',
        paid: 'emerald',
        failed: 'rose',
    }[status] || 'slate';
}

export function Field({ label, required, children, hint }) {
    return (
        <label className="block">
            <span className="block text-sm font-medium text-slate-600 mb-1">
                {label}{required && <span className="text-rose-500"> *</span>}
            </span>
            {children}
            {hint && <span className="block text-xs text-slate-400 mt-1">{hint}</span>}
        </label>
    );
}

export const inputCls = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400';

export function Alert({ type = 'info', children, onClose }) {
    if (!children) return null;
    const styles = {
        info: 'bg-sky-50 border-sky-200 text-sky-800',
        success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
        warning: 'bg-amber-50 border-amber-200 text-amber-800',
        error: 'bg-rose-50 border-rose-200 text-rose-800',
    };
    return (
        <div className={`border rounded-lg px-4 py-2.5 text-sm mb-4 flex justify-between items-start gap-3 ${styles[type]}`}>
            <div className="whitespace-pre-line">{children}</div>
            {onClose && <button onClick={onClose} className="text-current opacity-50 hover:opacity-100">✕</button>}
        </div>
    );
}

export function Spinner() {
    return (
        <div className="flex justify-center py-10">
            <div className="w-8 h-8 border-3 border-sky-500 border-t-transparent rounded-full animate-spin" />
        </div>
    );
}

export function KeyVal({ k, v }) {
    return (
        <div className="flex gap-3 text-sm py-1.5 border-b border-slate-100 last:border-0">
            <span className="text-slate-400 w-28 shrink-0">{k}</span>
            <span className="text-slate-700 font-medium">{v ?? '—'}</span>
        </div>
    );
}

export function Modal({ title, children, onClose, footer }) {
    return (
        <div className="fixed inset-0 bg-black/40 z-40 flex items-center justify-center p-4" onClick={onClose}>
            <div className="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-5 py-3 border-b border-slate-100">
                    <h3 className="font-semibold text-slate-800">{title}</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <div className="p-5">{children}</div>
                {footer && <div className="px-5 py-3 border-t border-slate-100 flex justify-end gap-2">{footer}</div>}
            </div>
        </div>
    );
}
