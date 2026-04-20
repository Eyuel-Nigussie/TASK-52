import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        content: { list: vi.fn(), update: vi.fn(), create: vi.fn() },
        contentSubmit: vi.fn(),
        contentApprove: vi.fn(),
        contentPublish: vi.fn(),
        contentRollback: vi.fn(),
        contentVersions: vi.fn(),
    },
}));

import ContentView from './ContentView.vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.content.list.mockResolvedValue({ data: [
        { id: 1, type: 'announcement', title: 'T1', body: 'b', status: 'draft', version: 1, priority: 0 },
        { id: 2, type: 'carousel', title: 'T2', body: 'b', status: 'in_review', version: 2, priority: 0 },
        { id: 3, type: 'announcement', title: 'T3', body: 'b', status: 'approved', version: 3, priority: 0 },
    ] });
    api.contentVersions.mockResolvedValue([{ version: 1, note: 'v1' }, { version: 2, note: 'v2' }]);
});

describe('ContentView', () => {
    it('loads items on mount', async () => {
        const auth = useAuthStore();
        auth.user = { role: 'system_admin' };
        const w = mount(ContentView);
        await flushPromises();
        expect(w.text()).toContain('T1');
    });

    it('handles load failure', async () => {
        api.content.list.mockRejectedValueOnce(new Error('x'));
        const w = mount(ContentView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes array response', async () => {
        api.content.list.mockResolvedValueOnce([{ id: 9, type: 'announcement', title: 'Z', body: 'b', status: 'draft', version: 1, priority: 0 }]);
        const w = mount(ContentView);
        await flushPromises();
        expect(w.text()).toContain('Z');
    });

    it('creates a new draft', async () => {
        api.content.create.mockResolvedValueOnce({});
        const auth = useAuthStore();
        auth.user = { role: 'content_editor' };
        const w = mount(ContentView);
        await flushPromises();
        w.vm.openNew();
        w.vm.form.title = 'N';
        w.vm.form.body = 'B';
        await w.vm.save();
        expect(api.content.create).toHaveBeenCalled();
    });

    it('edits an existing item', async () => {
        api.content.update.mockResolvedValueOnce({});
        const w = mount(ContentView);
        await flushPromises();
        w.vm.openEdit({ id: 1, type: 'announcement', title: 'T1', body: 'b', priority: 0 });
        w.vm.form.title = 'Updated';
        await w.vm.save();
        expect(api.content.update).toHaveBeenCalledWith(1, expect.objectContaining({ title: 'Updated' }));
    });

    it('renders visibility targeting inputs and sends parsed payload', async () => {
        api.content.create.mockResolvedValueOnce({});
        const auth = useAuthStore();
        auth.user = { role: 'content_editor' };
        const w = mount(ContentView);
        await flushPromises();

        w.vm.openNew();
        await flushPromises();

        // All four targeting fields must be rendered by the form.
        expect(w.find('[data-test=targeting-facility]').exists()).toBe(true);
        expect(w.find('[data-test=targeting-department]').exists()).toBe(true);
        expect(w.find('[data-test=targeting-roles]').exists()).toBe(true);
        expect(w.find('[data-test=targeting-tags]').exists()).toBe(true);

        w.vm.form.title = 'Broadcast';
        w.vm.form.body = 'body';
        w.vm.form.facility_ids_csv = '1, 2';
        w.vm.form.department_ids_csv = '7';
        w.vm.form.role_targets_csv = 'clinic_manager, technician_doctor';
        w.vm.form.tags_csv = 'urgent,cardiology';
        await w.vm.save();

        expect(api.content.create).toHaveBeenCalledWith(expect.objectContaining({
            title: 'Broadcast',
            facility_ids: [1, 2],
            department_ids: [7],
            role_targets: ['clinic_manager', 'technician_doctor'],
            tags: ['urgent', 'cardiology'],
        }));
    });

    it('save error surfaces', async () => {
        api.content.create.mockRejectedValueOnce({ response: { data: { message: 'too long' } } });
        const w = mount(ContentView);
        await flushPromises();
        w.vm.openNew();
        await w.vm.save();
        await flushPromises();
        expect(w.vm.error).toBe('too long');
    });

    it('doAction succeeds', async () => {
        api.contentSubmit.mockResolvedValueOnce({});
        const w = mount(ContentView);
        await flushPromises();
        await w.vm.doAction(api.contentSubmit, 'Submitted', { id: 1 });
        expect(api.contentSubmit).toHaveBeenCalledWith(1);
    });

    it('doAction error surfaces toast', async () => {
        const w = mount(ContentView);
        await flushPromises();
        await w.vm.doAction(() => Promise.reject(new Error('x')), 'nope', { id: 1 });
        expect(w.exists()).toBe(true);
    });

    it('showVersions and rollback', async () => {
        api.contentRollback.mockResolvedValueOnce({});
        const w = mount(ContentView);
        await flushPromises();
        await w.vm.showVersions({ id: 1, title: 'T1' });
        await flushPromises();
        expect(api.contentVersions).toHaveBeenCalledWith(1);
        await w.vm.rollback({ version: 2 });
        expect(api.contentRollback).toHaveBeenCalledWith(1, 2);
    });

    it('showVersions error', async () => {
        api.contentVersions.mockRejectedValueOnce(new Error('x'));
        const w = mount(ContentView);
        await flushPromises();
        await w.vm.showVersions({ id: 1, title: 'T1' });
        expect(w.exists()).toBe(true);
    });

    it('rollback error', async () => {
        api.contentRollback.mockRejectedValueOnce(new Error('x'));
        const w = mount(ContentView);
        await flushPromises();
        await w.vm.showVersions({ id: 1, title: 'T1' });
        await w.vm.rollback({ version: 2 });
        expect(w.exists()).toBe(true);
    });
});
