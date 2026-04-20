import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';

vi.mock('@/api', () => ({
    api: {
        logout: vi.fn().mockResolvedValue({}),
    },
}));

import AppLayout from './AppLayout.vue';
import { useAuthStore } from '@/stores/auth';

function mountLayout(user = { name: 'Admin', role: 'system_admin' }) {
    const router = createRouter({ history: createMemoryHistory(), routes: [
        { path: '/', component: AppLayout, children: [
            { path: 'dashboard', component: { template: '<div>d</div>' } },
            { path: 'login', component: { template: '<div>l</div>' } },
        ]},
        { path: '/login', component: { template: '<div>LOGIN</div>' } },
    ]});
    const auth = useAuthStore();
    auth.token = 'tk';
    auth.user = user;
    router.push('/');
    return mount(AppLayout, { global: { plugins: [router] } });
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('AppLayout', () => {
    it('renders user nav for admin', async () => {
        const w = mountLayout();
        await flushPromises();
        expect(w.text()).toContain('Users');
        expect(w.text()).toContain('Admin');
    });

    it('hides admin-only nav for non-admin', async () => {
        const w = mountLayout({ name: 'Clerk', role: 'inventory_clerk' });
        await flushPromises();
        expect(w.text()).not.toContain('Users');
    });

    it('logout clears auth and navigates to /login', async () => {
        const w = mountLayout();
        await flushPromises();
        await w.find('button').trigger('click');
        await flushPromises();
        const auth = useAuthStore();
        expect(auth.token).toBeNull();
    });
});
