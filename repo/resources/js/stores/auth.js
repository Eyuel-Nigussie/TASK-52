import { defineStore } from 'pinia';
import { api } from '@/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        // Token lives in memory only — never persisted to localStorage.
        // Session is restored on page load via the HttpOnly vetops_session cookie
        // by calling refreshSession() in App.vue.
        token: null,
        user: null,
        captchaRequired: false,
        captchaChallenge: '',
        requiresPasswordChange: false,
        loading: false,
    }),
    getters: {
        isAuthenticated: (s) => Boolean(s.token && s.user),
        role: (s) => s.user?.role ?? null,
    },
    actions: {
        async login(credentials) {
            this.loading = true;
            try {
                const res = await api.login(credentials);
                this.token = res.token;
                this.user = res.user;
                this.captchaRequired = Boolean(res.captcha_required);
                this.captchaChallenge = '';
                this.requiresPasswordChange = Boolean(res.requires_password_change);
                return res;
            } finally {
                this.loading = false;
            }
        },
        async refresh() {
            if (!this.token) return null;
            const user = await api.me();
            this.user = user;
            return user;
        },
        async refreshSession() {
            // Restores in-memory token from the server-side HttpOnly session cookie.
            try {
                const res = await api.refreshSession();
                this.token = res.token;
                this.user = res.user;
                this.requiresPasswordChange = Boolean(res.requires_password_change);
                return res;
            } catch {
                this.clear();
                return null;
            }
        },
        async logout() {
            try {
                if (this.token) await api.logout();
            } catch {
                // network or 401 — still clear local state
            } finally {
                this.clear();
            }
        },
        clear() {
            this.token = null;
            this.user = null;
            this.captchaRequired = false;
            this.captchaChallenge = '';
            this.requiresPasswordChange = false;
        },
        async checkCaptcha(username) {
            if (!username) return false;
            const res = await api.captchaStatus(username);
            this.captchaRequired = Boolean(res.captcha_required);
            this.captchaChallenge = res.challenge ?? '';
            return this.captchaRequired;
        },
    },
});
