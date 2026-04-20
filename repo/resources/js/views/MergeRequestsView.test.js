import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        mergeRequests: { list: vi.fn() },
        mergeApprove: vi.fn(),
        mergeReject: vi.fn(),
    },
}));

import MergeRequestsView from './MergeRequestsView.vue';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.mergeRequests.list.mockResolvedValue({ data: [{ id: 1, entity_type: 'patient', source_id: 1, target_id: 2, status: 'pending' }] });
});

describe('MergeRequestsView', () => {
    it('loads rows', async () => {
        const w = mount(MergeRequestsView);
        await flushPromises();
        expect(w.text()).toContain('patient');
    });

    it('handles load failure', async () => {
        api.mergeRequests.list.mockRejectedValueOnce(new Error('x'));
        const w = mount(MergeRequestsView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes array response', async () => {
        api.mergeRequests.list.mockResolvedValueOnce([{ id: 2, entity_type: 'doctor', status: 'approved' }]);
        const w = mount(MergeRequestsView);
        await flushPromises();
        expect(w.text()).toContain('doctor');
    });

    it('approves', async () => {
        api.mergeApprove.mockResolvedValueOnce({});
        const w = mount(MergeRequestsView);
        await flushPromises();
        await w.vm.approve({ id: 1 });
        expect(api.mergeApprove).toHaveBeenCalledWith(1);
    });

    it('approve error', async () => {
        api.mergeApprove.mockRejectedValueOnce(new Error('x'));
        const w = mount(MergeRequestsView);
        await flushPromises();
        await w.vm.approve({ id: 1 });
        expect(w.exists()).toBe(true);
    });

    it('rejects', async () => {
        api.mergeReject.mockResolvedValueOnce({});
        const w = mount(MergeRequestsView);
        await flushPromises();
        await w.vm.reject({ id: 1 });
        expect(api.mergeReject).toHaveBeenCalledWith(1);
    });

    it('reject error', async () => {
        api.mergeReject.mockRejectedValueOnce(new Error('x'));
        const w = mount(MergeRequestsView);
        await flushPromises();
        await w.vm.reject({ id: 1 });
        expect(w.exists()).toBe(true);
    });
});
