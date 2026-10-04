import { createContext, useContext, useEffect, useState } from 'react'
import { api, getToken, setToken } from './api'

const AuthCtx = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(getToken()))

  useEffect(() => {
    if (getToken()) {
      api('GET', '/auth/me')
        .then((u) => setUser(u))
        .catch(() => setToken(null))
        .finally(() => setLoading(false))
    }
  }, [])

  async function login(phone, password) {
    const u = await api('POST', '/auth/login', { phone, password })
    setToken(u.token)
    setUser(u)
  }

  async function register(payload) {
    const u = await api('POST', '/auth/register', payload)
    setToken(u.token)
    setUser(u)
  }

  function logout() {
    setToken(null)
    setUser(null)
  }

  return (
    <AuthCtx.Provider value={{ user, loading, login, register, logout }}>
      {children}
    </AuthCtx.Provider>
  )
}

export const useAuth = () => useContext(AuthCtx)
