import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { setActivePinia, createPinia } from 'pinia';

import LoginView from '@/views/LoginView.vue';
import { setClient, resetClient } from '@/api';
import { useAuthStore } from '@/stores/auth';

function makeRouter(query = {}) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: { template: '<div>home</div>' } },
            { path: '/login', component: LoginView },
            { path: '/dashboard', component: { template: '<div>dashboard</div>' } },
            { path: '/target', component: { template: '<div>target</div>' } },
            { path: '/change-password', component: { template: '<div>change-password</div>' } },
        ],
    });
    const qs = new URLSearchParams(query).toString();
    router.push(qs ? `/login?${qs}` : '/login');
    return router;
}

function makeClient(overrides = {}) {
    return {
        get: vi.fn(async (url, opts) => {
            if (url === '/auth/captcha-status') {
                return { data: { captcha_required: false, challenge: '' } };
            }
            throw new Error(`Unhandled GET ${url} ${JSON.stringify(opts || {})}`);
        }),
        post: vi.fn(async (url, body) => {
            if (url === '/auth/login') {
                return { data: { token: 'tok', user: { role: 'system_admin' }, captcha_required: false } };
            }
            throw new Error(`Unhandled POST ${url} ${JSON.stringify(body || {})}`);
        }),
        ...overrides,
    };
}

describe('Login flow integration (view -> store -> real api module)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    afterEach(() => {
        resetClient();
    });

    it('checks captcha status when username changes', async () => {
        const client = makeClient();
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await flushPromises();
        await w.find('input[type=text]').setValue('admin');
        await flushPromises();

        expect(client.get).toHaveBeenCalledWith('/auth/captcha-status', { params: { username: 'admin' } });
    });

    it('renders server-provided captcha challenge', async () => {
        const client = makeClient({
            get: vi.fn(async () => ({ data: { captcha_required: true, challenge: '3 + 4' } })),
        });
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('locked_user');
        await flushPromises();

        expect(w.find('[data-test=captcha]').exists()).toBe(true);
        expect(w.find('[data-test=captcha-challenge]').text()).toContain('3 + 4');
    });

    it('submits login through api module and redirects to dashboard', async () => {
        const client = makeClient();
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('admin');
        await w.find('input[type=password]').setValue('StrongPass123!');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(client.post).toHaveBeenCalledWith('/auth/login', {
            username: 'admin',
            password: 'StrongPass123!',
            captcha_token: undefined,
        });
        expect(router.currentRoute.value.path).toBe('/dashboard');
    });

    it('honors redirect query parameter after login', async () => {
        const client = makeClient();
        setClient(client);
        const router = makeRouter({ redirect: '/target' });
        await router.isReady();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('admin');
        await w.find('input[type=password]').setValue('StrongPass123!');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/target');
    });

    it('routes to change-password when login response requires it', async () => {
        const client = makeClient({
            post: vi.fn(async () => ({
                data: {
                    token: 'tok',
                    user: { role: 'system_admin' },
                    captcha_required: false,
                    requires_password_change: true,
                },
            })),
        });
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('admin');
        await w.find('input[type=password]').setValue('TempPass123!');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/change-password');
    });

    it('shows backend error message on failed login', async () => {
        const client = makeClient({
            post: vi.fn(async () => {
                const err = new Error('bad creds');
                err.response = { data: { message: 'Invalid credentials from API' }, status: 422 };
                throw err;
            }),
        });
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('admin');
        await w.find('input[type=password]').setValue('wrong');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(w.find('[role=alert]').text()).toContain('Invalid credentials from API');
    });

    it('clears captcha flags when username is emptied', async () => {
        const client = makeClient({
            get: vi.fn(async () => ({ data: { captcha_required: true, challenge: '1 + 1' } })),
        });
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('abc');
        await flushPromises();
        await w.find('input[type=text]').setValue('');
        await flushPromises();

        const auth = useAuthStore();
        expect(auth.captchaRequired).toBe(false);
        expect(auth.captchaChallenge).toBe('');
    });

    it('passes captcha token when challenge is required', async () => {
        const client = makeClient({
            get: vi.fn(async () => ({ data: { captcha_required: true, challenge: '2 + 5' } })),
            post: vi.fn(async () => ({ data: { token: 'tok', user: { role: 'system_admin' }, captcha_required: true } })),
        });
        setClient(client);
        const router = makeRouter();
        const w = mount(LoginView, { global: { plugins: [router] } });

        await w.find('input[type=text]').setValue('admin');
        await flushPromises();
        await w.find('[data-test=captcha-input]').setValue('7');
        await w.find('input[type=password]').setValue('StrongPass123!');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(client.post).toHaveBeenCalledWith('/auth/login', {
            username: 'admin',
            password: 'StrongPass123!',
            captcha_token: '7',
        });
    });
});
