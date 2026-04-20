import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        stocktakes: vi.fn(),
        stocktakeShow: vi.fn(),
        stocktakeStart: vi.fn(),
        stocktakeAddEntry: vi.fn(),
        stocktakeApproveEntry: vi.fn(),
        stocktakeClose: vi.fn(),
    },
}));

import StocktakeView from './StocktakeView.vue';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.stocktakes.mockResolvedValue({ data: [{ id: 1, storeroom: { name: 'A' }, status: 'open', started_at: new Date().toISOString() }] });
    api.stocktakeShow.mockResolvedValue({ id: 1, status: 'open', entries: [
        { id: 1, item: { name: 'I' }, counted_quantity: 10, expected_quantity: 10, variance_pct: 0, status: 'approved' },
        { id: 2, item: { name: 'J' }, counted_quantity: 8, expected_quantity: 10, variance_pct: 20, status: 'pending' },
    ] });
    api.stocktakeStart.mockResolvedValue({ id: 2, status: 'open', entries: [] });
    api.stocktakeAddEntry.mockResolvedValue({});
    api.stocktakeApproveEntry.mockResolvedValue({});
    api.stocktakeClose.mockResolvedValue({});
});

describe('StocktakeView', () => {
    it('loads sessions on mount', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        expect(w.text()).toContain('open');
    });

    it('handles list failure', async () => {
        api.stocktakes.mockRejectedValueOnce(new Error('x'));
        const w = mount(StocktakeView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes array sessions response', async () => {
        api.stocktakes.mockResolvedValueOnce([{ id: 9, storeroom: { name: 'Z' }, status: 'closed', started_at: new Date().toISOString() }]);
        const w = mount(StocktakeView);
        await flushPromises();
        expect(w.text()).toContain('closed');
    });

    it('opens a session', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        expect(api.stocktakeShow).toHaveBeenCalledWith(1);
    });

    it('openSession error surfaces', async () => {
        api.stocktakeShow.mockRejectedValueOnce(new Error('x'));
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        expect(w.exists()).toBe(true);
    });

    it('starts a session', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        w.vm.startData.storeroom_id = 5;
        await w.vm.startSession();
        expect(api.stocktakeStart).toHaveBeenCalledWith({ storeroom_id: 5 });
    });

    it('start error sets message', async () => {
        api.stocktakeStart.mockRejectedValueOnce({ response: { data: { message: 'nope' } } });
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.startSession();
        await flushPromises();
        expect(w.vm.startError).toBe('nope');
    });

    it('adds entry when session selected', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        w.vm.entryForm.item_id = 2;
        w.vm.entryForm.counted_quantity = 5;
        await w.vm.addEntry();
        expect(api.stocktakeAddEntry).toHaveBeenCalled();
    });

    it('addEntry without session is a noop', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        w.vm.selected = null;
        await w.vm.addEntry();
        expect(api.stocktakeAddEntry).not.toHaveBeenCalled();
    });

    it('addEntry error sets message', async () => {
        api.stocktakeAddEntry.mockRejectedValueOnce({ response: { data: { message: 'variance' } } });
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.addEntry();
        await flushPromises();
        expect(w.vm.entryError).toBe('variance');
    });

    it('approves an entry with a reason prompt', async () => {
        vi.stubGlobal('prompt', vi.fn(() => 'Manager override — manual recount verified'));
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.approveEntry({ id: 2 });
        expect(api.stocktakeApproveEntry).toHaveBeenCalledWith(
            1,
            2,
            'Manager override — manual recount verified',
        );
        vi.unstubAllGlobals();
    });

    it('approve entry skips submit when reason is blank', async () => {
        vi.stubGlobal('prompt', vi.fn(() => ''));
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.approveEntry({ id: 2 });
        expect(api.stocktakeApproveEntry).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it('approve entry error surfaces', async () => {
        vi.stubGlobal('prompt', vi.fn(() => 'Recount approved'));
        api.stocktakeApproveEntry.mockRejectedValueOnce(new Error('x'));
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.approveEntry({ id: 2 });
        expect(w.exists()).toBe(true);
        vi.unstubAllGlobals();
    });

    it('closes a session', async () => {
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.closeSession();
        expect(api.stocktakeClose).toHaveBeenCalledWith(1);
    });

    it('close error surfaces', async () => {
        api.stocktakeClose.mockRejectedValueOnce(new Error('x'));
        const w = mount(StocktakeView);
        await flushPromises();
        await w.vm.openSession({ id: 1 });
        await w.vm.closeSession();
        expect(w.exists()).toBe(true);
    });
});
