import { describe, it, expect, beforeEach } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { setActivePinia, createPinia } from 'pinia';
import { createAppRouter, routes, installGuards } from './index.js';
import { useAuthStore } from '@/stores/auth';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('routes', () => {
    it('exports a routes array', () => {
        expect(Array.isArray(routes)).toBe(true);
        expect(routes.find((r) => r.name === 'login')).toBeDefined();
        expect(routes.find((r) => r.name === 'not-found')).toBeDefined();
    });
});

describe('guards', () => {
    it('redirects unauthenticated traffic to login', async () => {
        const router = createAppRouter(createMemoryHistory());
        await router.push('/dashboard');
        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/dashboard');
    });

    it('allows public routes', async () => {
        const router = createAppRouter(createMemoryHistory());
        await router.push('/login');
        expect(router.currentRoute.value.name).toBe('login');
    });

    it('lets authenticated users through', async () => {
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();
        auth.token = 'x';
        auth.user = { role: 'system_admin' };
        await router.push('/dashboard');
        expect(router.currentRoute.value.name).toBe('dashboard');
    });

    it('bounces off role-restricted routes for unauthorized users', async () => {
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();
        auth.token = 'x';
        auth.user = { role: 'technician_doctor' };
        await router.push('/users');
        expect(router.currentRoute.value.name).toBe('dashboard');
    });

    it('installGuards returns the router', () => {
        const r = createAppRouter(createMemoryHistory());
        expect(installGuards(r)).toBe(r);
    });

    it('tablet review path is public', async () => {
        const router = createAppRouter(createMemoryHistory());
        await router.push('/tablet/reviews/42');
        expect(router.currentRoute.value.name).toBe('tablet-review');
    });

    it('unknown routes hit not-found', async () => {
        const router = createAppRouter(createMemoryHistory());
        await router.push('/does/not/exist');
        expect(router.currentRoute.value.name).toBe('not-found');
    });

    it('redirects to change-password when requiresPasswordChange is set', async () => {
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();
        auth.token = 'x';
        auth.user = { role: 'system_admin' };
        auth.requiresPasswordChange = true;
        await router.push('/dashboard');
        expect(router.currentRoute.value.name).toBe('change-password');
    });

    it('allows access to change-password when requiresPasswordChange is set', async () => {
        const router = createAppRouter(createMemoryHistory());
        const auth = useAuthStore();
        auth.token = 'x';
        auth.user = { role: 'system_admin' };
        auth.requiresPasswordChange = true;
        await router.push('/change-password');
        expect(router.currentRoute.value.name).toBe('change-password');
    });
});
