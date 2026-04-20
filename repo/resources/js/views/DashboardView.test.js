import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        rentalOverdue: vi.fn(),
        inventoryLowStock: vi.fn(),
    },
}));

import DashboardView from './DashboardView.vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
});

describe('DashboardView', () => {
    it('loads and displays overdue + low stock', async () => {
        api.rentalOverdue.mockResolvedValueOnce([{ id: 1, asset: { name: 'Pump' } }]);
        api.inventoryLowStock.mockResolvedValueOnce([{ item_id: 5, storeroom_id: 2, on_hand: 3, item: { name: 'Gauze' } }]);
        const auth = useAuthStore();
        auth.user = { name: 'Admin', role: 'system_admin' };
        const w = mount(DashboardView);
        await flushPromises();
        expect(w.text()).toContain('Pump');
        expect(w.text()).toContain('Gauze');
        expect(w.text()).toContain('Admin');
    });

    it('tolerates non-array responses', async () => {
        api.rentalOverdue.mockResolvedValueOnce({ data: [{ id: 2 }] });
        api.inventoryLowStock.mockResolvedValueOnce({ data: [{ item_id: 3, storeroom_id: 1 }] });
        const auth = useAuthStore();
        auth.user = { name: 'M', role: 'clinic_manager' };
        const w = mount(DashboardView);
        await flushPromises();
        expect(w.findAll('[data-test=overdue-item]').length).toBe(1);
    });

    it('shows empty states', async () => {
        api.rentalOverdue.mockResolvedValueOnce([]);
        api.inventoryLowStock.mockResolvedValueOnce([]);
        const auth = useAuthStore();
        auth.user = { name: 'M', role: 'clinic_manager' };
        const w = mount(DashboardView);
        await flushPromises();
        expect(w.text()).toContain('No overdue rentals');
        expect(w.text()).toContain('Stock levels are healthy');
    });

    it('falls back to empty when APIs throw', async () => {
        api.rentalOverdue.mockRejectedValueOnce(new Error('x'));
        api.inventoryLowStock.mockRejectedValueOnce(new Error('y'));
        const auth = useAuthStore();
        auth.user = { name: 'M', role: 'clinic_manager' };
        const w = mount(DashboardView);
        await flushPromises();
        expect(w.text()).toContain('No overdue rentals');
    });

    it('renders generic user when no user', async () => {
        api.rentalOverdue.mockResolvedValueOnce([]);
        api.inventoryLowStock.mockResolvedValueOnce([]);
        const w = mount(DashboardView);
        await flushPromises();
        expect(w.text()).toContain('User');
    });
});
