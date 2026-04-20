import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => {
    const api = {
        login: vi.fn(),
        logout: vi.fn(),
        me: vi.fn(),
        refreshSession: vi.fn(),
        captchaStatus: vi.fn(),
    };
    return { api };
});

import { useAuthStore } from './auth.js';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('auth store', () => {
    it('starts unauthenticated', () => {
        const s = useAuthStore();
        expect(s.isAuthenticated).toBe(false);
        expect(s.role).toBeNull();
    });

    it('token is null on init — not read from localStorage', () => {
        const s = useAuthStore();
        expect(s.token).toBeNull();
    });

    it('login stores token and user in memory only', async () => {
        api.login.mockResolvedValueOnce({ token: 't1', user: { role: 'system_admin' }, captcha_required: false });
        const s = useAuthStore();
        await s.login({ username: 'u', password: 'p' });
        expect(s.token).toBe('t1');
        expect(s.user.role).toBe('system_admin');
        expect(s.isAuthenticated).toBe(true);
        expect(s.role).toBe('system_admin');
    });

    it('login captures captcha_required true', async () => {
        api.login.mockResolvedValueOnce({ token: 't', user: {}, captcha_required: true });
        const s = useAuthStore();
        await s.login({ username: 'u', password: 'p' });
        expect(s.captchaRequired).toBe(true);
    });

    it('login sets requiresPasswordChange when flag is true', async () => {
        api.login.mockResolvedValueOnce({ token: 't', user: {}, captcha_required: false, requires_password_change: true });
        const s = useAuthStore();
        await s.login({ username: 'u', password: 'p' });
        expect(s.requiresPasswordChange).toBe(true);
    });

    it('login re-throws errors and resets loading', async () => {
        api.login.mockRejectedValueOnce(new Error('nope'));
        const s = useAuthStore();
        await expect(s.login({})).rejects.toThrow('nope');
        expect(s.loading).toBe(false);
    });

    it('refresh returns null when no token', async () => {
        const s = useAuthStore();
        const r = await s.refresh();
        expect(r).toBeNull();
        expect(api.me).not.toHaveBeenCalled();
    });

    it('refresh updates user when token present', async () => {
        api.me.mockResolvedValueOnce({ id: 1, role: 'clinic_manager' });
        const s = useAuthStore();
        s.token = 'x';
        const u = await s.refresh();
        expect(u.role).toBe('clinic_manager');
        expect(s.user.id).toBe(1);
    });

    it('refreshSession restores token and user from cookie', async () => {
        api.refreshSession.mockResolvedValueOnce({
            token: 'restored',
            user: { role: 'inventory_clerk' },
            requires_password_change: false,
        });
        const s = useAuthStore();
        await s.refreshSession();
        expect(s.token).toBe('restored');
        expect(s.user.role).toBe('inventory_clerk');
        expect(s.requiresPasswordChange).toBe(false);
    });

    it('refreshSession clears auth on failure', async () => {
        api.refreshSession.mockRejectedValueOnce(new Error('no cookie'));
        const s = useAuthStore();
        s.token = 'stale';
        s.user = { role: 'x' };
        await s.refreshSession();
        expect(s.token).toBeNull();
        expect(s.user).toBeNull();
    });

    it('logout calls api and clears state', async () => {
        api.logout.mockResolvedValueOnce({ ok: true });
        const s = useAuthStore();
        s.token = 't';
        s.user = { role: 'x' };
        await s.logout();
        expect(s.token).toBeNull();
        expect(s.user).toBeNull();
    });

    it('logout ignores api errors but still clears', async () => {
        api.logout.mockRejectedValueOnce(new Error('network'));
        const s = useAuthStore();
        s.token = 't';
        s.user = { role: 'x' };
        await s.logout();
        expect(s.token).toBeNull();
    });

    it('logout with no token skips api call', async () => {
        const s = useAuthStore();
        await s.logout();
        expect(api.logout).not.toHaveBeenCalled();
    });

    it('clear resets all state', () => {
        const s = useAuthStore();
        s.token = 'tk';
        s.user = { id: 1 };
        s.requiresPasswordChange = true;
        s.clear();
        expect(s.token).toBeNull();
        expect(s.user).toBeNull();
        expect(s.requiresPasswordChange).toBe(false);
    });

    it('checkCaptcha skips empty username', async () => {
        const s = useAuthStore();
        const out = await s.checkCaptcha('');
        expect(out).toBe(false);
        expect(api.captchaStatus).not.toHaveBeenCalled();
    });

    it('checkCaptcha updates captchaRequired', async () => {
        api.captchaStatus.mockResolvedValueOnce({ captcha_required: true });
        const s = useAuthStore();
        const out = await s.checkCaptcha('bob');
        expect(out).toBe(true);
        expect(s.captchaRequired).toBe(true);
    });

    it('checkCaptcha stores the challenge text for UI rendering', async () => {
        api.captchaStatus.mockResolvedValueOnce({ captcha_required: true, challenge: '2 + 5' });
        const s = useAuthStore();
        await s.checkCaptcha('bob');
        expect(s.captchaChallenge).toBe('2 + 5');
    });

    it('checkCaptcha clears challenge when not required', async () => {
        api.captchaStatus.mockResolvedValueOnce({ captcha_required: false });
        const s = useAuthStore();
        s.captchaChallenge = 'stale';
        await s.checkCaptcha('bob');
        expect(s.captchaChallenge).toBe('');
    });
});
