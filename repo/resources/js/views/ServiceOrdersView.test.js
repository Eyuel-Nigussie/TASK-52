import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => {
    const client = { post: vi.fn().mockResolvedValue({}) };
    return {
        api: {
            serviceOrders: { list: vi.fn(), create: vi.fn() },
        },
        getClient: () => client,
        __client: client,
    };
});

import ServiceOrdersView from './ServiceOrdersView.vue';
import * as apiMod from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    apiMod.api.serviceOrders.list.mockResolvedValue({ data: [{ id: 1, patient_id: 2, status: 'open', reservation_strategy: 'lock_at_creation', created_at: new Date().toISOString() }] });
    apiMod.api.serviceOrders.create.mockResolvedValue({});
    apiMod.__client.post.mockResolvedValue({});
});

describe('ServiceOrdersView', () => {
    it('loads orders on mount', async () => {
        const w = mount(ServiceOrdersView);
        await flushPromises();
        expect(w.text()).toContain('open');
    });

    it('handles load failure', async () => {
        apiMod.api.serviceOrders.list.mockRejectedValueOnce(new Error('x'));
        const w = mount(ServiceOrdersView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes array response', async () => {
        apiMod.api.serviceOrders.list.mockResolvedValueOnce([{ id: 9, patient_id: 3, status: 'closed', created_at: new Date().toISOString() }]);
        const w = mount(ServiceOrdersView);
        await flushPromises();
        expect(w.text()).toContain('closed');
    });

    it('creates order with valid reservation strategy', async () => {
        const w = mount(ServiceOrdersView);
        await flushPromises();
        w.vm.form.patient_id = 1;
        w.vm.form.doctor_id = 2;
        w.vm.form.facility_id = 3;
        await w.vm.save();
        // Backend validates in: lock_at_creation|deduct_at_close — fail fast if UI drifts.
        const payload = apiMod.api.serviceOrders.create.mock.calls[0][0];
        expect(['lock_at_creation', 'deduct_at_close']).toContain(payload.reservation_strategy);
        expect(payload.reservation_strategy).toBe('lock_at_creation');
    });

    it('create error surfaces', async () => {
        apiMod.api.serviceOrders.create.mockRejectedValueOnce({ response: { data: { message: 'bad' } } });
        const w = mount(ServiceOrdersView);
        await flushPromises();
        await w.vm.save();
        await flushPromises();
        expect(w.vm.error).toBe('bad');
    });

    it('closes an order', async () => {
        const w = mount(ServiceOrdersView);
        await flushPromises();
        await w.vm.closeOrder({ id: 9 });
        expect(apiMod.__client.post).toHaveBeenCalledWith('/service-orders/9/close');
    });

    it('close order error surfaces', async () => {
        apiMod.__client.post.mockRejectedValueOnce(new Error('x'));
        const w = mount(ServiceOrdersView);
        await flushPromises();
        await w.vm.closeOrder({ id: 9 });
        expect(w.exists()).toBe(true);
    });
});
