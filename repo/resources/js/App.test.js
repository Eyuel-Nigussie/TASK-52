import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        me: vi.fn(),
        refreshSession: vi.fn(),
    },
}));

import App from './App.vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('App.vue', () => {
    it('attempts session restore via refreshSession when no user', async () => {
        api.refreshSession.mockResolvedValueOnce({ token: 'tk', user: { role: 'system_admin' }, requires_password_change: false });
        mount(App);
        await flushPromises();
        expect(api.refreshSession).toHaveBeenCalled();
    });

    it('clears auth when refreshSession fails', async () => {
        api.refreshSession.mockRejectedValueOnce(new Error('no cookie'));
        const auth = useAuthStore();
        auth.token = 'stale';
        mount(App);
        await flushPromises();
        expect(auth.token).toBeNull();
    });

    it('skips refreshSession when user is already set', async () => {
        const auth = useAuthStore();
        auth.token = 'tk';
        auth.user = { role: 'system_admin' };
        mount(App);
        await flushPromises();
        expect(api.refreshSession).not.toHaveBeenCalled();
    });
});
