const TOKEN_KEY = 'nuan_token';
const USER_KEY = 'nuan_user';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}
export function saveSession(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
}
export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}
export function getUser() {
    try {
        return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    } catch {
        return null;
    }
}

export class ApiError extends Error {
    constructor(message, code, status, context) {
        super(message);
        this.code = code;
        this.status = status;
        this.context = context;
    }
}

export async function api(path, options = {}) {
    const headers = { 'Accept': 'application/json', ...(options.headers || {}) };
    if (!(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }
    const token = getToken();
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    const res = await fetch(`/api/${path.replace(/^\//, '')}`, {
        ...options,
        headers,
        body: options.body && !(options.body instanceof FormData) ? JSON.stringify(options.body) : options.body,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new ApiError(
            data.message || `请求失败（${res.status}）`,
            data.error_code || 'error',
            res.status,
            data.context || null,
        );
    }
    return data;
}
