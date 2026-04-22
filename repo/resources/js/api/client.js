import axios from 'axios';

// Kept for reference only — tokens are no longer persisted to localStorage.
export const TOKEN_STORAGE_KEY = 'vetops.token';

// Stable per-workstation identifier stored in localStorage. Used as the
// throttle key for login attempts so that a shared IP (e.g. a clinic LAN)
// does not cause one locked-out terminal to lock out all others.
function getDeviceId() {
    if (typeof window === 'undefined' || !window.localStorage) return null;
    const key = 'vetops.device_id';
    let id = localStorage.getItem(key);
    if (!id) {
        id = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : [...Array(32)].map(() => Math.floor(Math.random() * 16).toString(16)).join('');
        localStorage.setItem(key, id);
    }
    return id;
}

export function createApiClient({ getToken, onUnauthorized } = {}) {
    const deviceId = getDeviceId();
    const instance = axios.create({
        baseURL: '/api',
        headers: {
            Accept: 'application/json',
            ...(deviceId ? { 'X-Device-ID': deviceId } : {}),
        },
        // withCredentials enables the HttpOnly refresh cookie and lets axios
        // automatically read the XSRF-TOKEN cookie and send X-XSRF-TOKEN.
        withCredentials: true,
    });

    instance.interceptors.request.use((config) => {
        const token = getToken?.();
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    });

    instance.interceptors.response.use(
        (res) => res,
        (error) => {
            if (error?.response?.status === 401 && typeof onUnauthorized === 'function') {
                onUnauthorized(error);
            }
            return Promise.reject(error);
        }
    );

    return instance;
}

export function extractErrorMessage(error, fallback = 'Request failed.') {
    if (!error) return fallback;
    const data = error.response?.data;
    if (!data) return error.message || fallback;
    if (typeof data === 'string') return data;
    if (data.message) return data.message;
    if (data.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return fallback;
}
