import React, { createContext, useContext, useEffect, useState } from 'react';
import { api, clearSession, getToken, getUser, saveSession } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(getUser());
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (getToken() && !user) {
            api('auth/me').then(({ user }) => setUser(user)).catch(() => logout());
        }
    }, []);

    async function login(email, password) {
        setLoading(true);
        try {
            const data = await api('auth/login', { method: 'POST', body: { email, password } });
            saveSession(data.token, data.user);
            setUser(data.user);
            return data.user;
        } finally {
            setLoading(false);
        }
    }

    async function register(payload) {
        setLoading(true);
        try {
            const data = await api('auth/register', { method: 'POST', body: payload });
            saveSession(data.token, data.user);
            setUser(data.user);
            return data.user;
        } finally {
            setLoading(false);
        }
    }

    function logout() {
        api('auth/logout', { method: 'POST' }).catch(() => {});
        clearSession();
        setUser(null);
    }

    return (
        <AuthContext.Provider value={{ user, loading, login, register, logout, setUser }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}
