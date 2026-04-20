import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';

vi.mock('@/api', () => ({
    api: {
        changePassword: vi.fn(),
    },
}));

import ChangePasswordView from './ChangePasswordView.vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';

function mountView() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/change-password', component: ChangePasswordView },
            { path: '/dashboard', component: { template: '<div>dash</div>' } },
        ],
    });
    router.push('/change-password');
    return mount(ChangePasswordView, { global: { plugins: [router] } });
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('ChangePasswordView', () => {
    it('renders the form', () => {
        const w = mountView();
        expect(w.find('[data-test=current-password]').exists()).toBe(true);
        expect(w.find('[data-test=new-password]').exists()).toBe(true);
        expect(w.find('[data-test=confirm-password]').exists()).toBe(true);
    });

    it('shows error when passwords do not match', async () => {
        const w = mountView();
        await w.find('[data-test=current-password]').setValue('OldPass1!Xyz');
        await w.find('[data-test=new-password]').setValue('NewPass1!Xyz1');
        await w.find('[data-test=confirm-password]').setValue('Different1!X');
        await w.find('form').trigger('submit');
        expect(w.find('[role=alert]').text()).toBe('Passwords do not match.');
        expect(api.changePassword).not.toHaveBeenCalled();
    });

    it('submits and shows success, clears requiresPasswordChange flag', async () => {
        api.changePassword.mockResolvedValueOnce({});
        const w = mountView();
        const auth = useAuthStore();
        auth.requiresPasswordChange = true;
        await w.find('[data-test=current-password]').setValue('OldPass1!Xyz');
        await w.find('[data-test=new-password]').setValue('NewPass1!Xyz1');
        await w.find('[data-test=confirm-password]').setValue('NewPass1!Xyz1');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(api.changePassword).toHaveBeenCalledWith({
            current_password: 'OldPass1!Xyz',
            password: 'NewPass1!Xyz1',
            password_confirmation: 'NewPass1!Xyz1',
        });
        expect(w.find('[role=status]').text()).toContain('Password changed successfully');
        expect(auth.requiresPasswordChange).toBe(false);
    });

    it('shows api error on failure', async () => {
        api.changePassword.mockRejectedValueOnce({ response: { data: { message: 'wrong current' } } });
        const w = mountView();
        await w.find('[data-test=current-password]').setValue('WrongOld1!X');
        await w.find('[data-test=new-password]').setValue('NewPass1!Xyz1');
        await w.find('[data-test=confirm-password]').setValue('NewPass1!Xyz1');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[role=alert]').text()).toBe('wrong current');
    });
});
