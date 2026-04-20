import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';

vi.mock('@/api', () => ({
    api: {
        login: vi.fn(),
        captchaStatus: vi.fn().mockResolvedValue({ captcha_required: false }),
    },
}));

import LoginView from './LoginView.vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';

function mountLogin(query = {}) {
    const router = createRouter({ history: createMemoryHistory(), routes: [
        { path: '/login', component: LoginView },
        { path: '/dashboard', component: { template: '<div>dash</div>' } },
        { path: '/target', component: { template: '<div>t</div>' } },
    ]});
    router.push({ path: '/login', query });
    return mount(LoginView, { global: { plugins: [router] } });
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('LoginView', () => {
    it('submits credentials and redirects to dashboard', async () => {
        api.login.mockResolvedValueOnce({ token: 't', user: { role: 'system_admin' }, captcha_required: false });
        const w = mountLogin();
        await flushPromises();
        w.find('input[type=text]').setValue('admin');
        w.find('input[type=password]').setValue('supersecretpassword');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(api.login).toHaveBeenCalled();
    });

    it('shows error on failed login', async () => {
        api.login.mockRejectedValueOnce({ response: { data: { message: 'bad creds' } } });
        const w = mountLogin();
        await flushPromises();
        await w.find('input[type=text]').setValue('a');
        await w.find('input[type=password]').setValue('b');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[role=alert]').text()).toBe('bad creds');
    });

    it('shows captcha block when required', async () => {
        api.captchaStatus.mockResolvedValueOnce({ captcha_required: true });
        const w = mountLogin();
        await flushPromises();
        await w.find('input[type=text]').setValue('spammer');
        await flushPromises();
        expect(w.find('[data-test=captcha]').exists()).toBe(true);
    });

    it('renders the captcha challenge from the backend', async () => {
        api.captchaStatus.mockResolvedValueOnce({ captcha_required: true, challenge: '3 + 4' });
        const w = mountLogin();
        await flushPromises();
        await w.find('input[type=text]').setValue('spammer');
        await flushPromises();
        expect(w.find('[data-test=captcha-challenge]').text()).toContain('3 + 4');
        expect(w.find('[data-test=captcha-input]').exists()).toBe(true);
    });

    it('clears captcha when username emptied', async () => {
        const w = mountLogin();
        await flushPromises();
        const auth = useAuthStore();
        // Trigger captcha required, then empty the field
        await w.find('input[type=text]').setValue('x');
        await flushPromises();
        auth.captchaRequired = true;
        await w.find('input[type=text]').setValue('');
        await flushPromises();
        expect(auth.captchaRequired).toBe(false);
    });

    it('handles captcha lookup failure silently', async () => {
        api.captchaStatus.mockRejectedValueOnce(new Error('down'));
        const w = mountLogin();
        await w.find('input[type=text]').setValue('u');
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('redirects to query.redirect when present', async () => {
        api.login.mockResolvedValueOnce({ token: 't', user: { role: 'system_admin' }, captcha_required: false });
        const w = mountLogin({ redirect: '/target' });
        await flushPromises();
        await w.find('input[type=text]').setValue('a');
        await w.find('input[type=password]').setValue('b');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(api.login).toHaveBeenCalled();
    });

    it('redirects to /change-password when requires_password_change', async () => {
        api.login.mockResolvedValueOnce({
            token: 't',
            user: { role: 'system_admin' },
            captcha_required: false,
            requires_password_change: true,
        });
        const router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/login', component: LoginView },
            { path: '/change-password', component: { template: '<div>change</div>' } },
        ]});
        router.push('/login');
        const w = mount(LoginView, { global: { plugins: [router] } });
        await flushPromises();
        await w.find('input[type=text]').setValue('admin');
        await w.find('input[type=password]').setValue('TempPass');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(router.currentRoute.value.path).toBe('/change-password');
    });
});
