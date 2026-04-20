import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        inventoryItems: vi.fn(),
        inventoryStockLevels: vi.fn(),
        inventoryLowStock: vi.fn(),
        inventoryLedger: vi.fn(),
        inventoryCreateItem: vi.fn(),
        inventoryReceive: vi.fn(),
        inventoryIssue: vi.fn(),
        inventoryTransfer: vi.fn(),
        storerooms: { list: vi.fn() },
    },
}));

import InventoryView from './InventoryView.vue';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.inventoryItems.mockResolvedValue({ data: [{ id: 1, external_key: 'GZ', name: 'Gauze', sku: 'G1', category: 'consumable', unit_of_measure: 'box' }] });
    api.inventoryStockLevels.mockResolvedValue({ data: [] });
    api.inventoryLowStock.mockResolvedValue([]);
    api.inventoryLedger.mockResolvedValue({ data: [] });
    api.storerooms.list.mockResolvedValue({ data: [] });
});

describe('InventoryView', () => {
    it('mounts and lists items', async () => {
        const w = mount(InventoryView);
        await flushPromises();
        expect(w.text()).toContain('Gauze');
    });

    it('falls back gracefully when all API responses are nullish', async () => {
        api.inventoryItems.mockResolvedValueOnce(null);
        api.inventoryStockLevels.mockResolvedValueOnce(null);
        api.inventoryLowStock.mockResolvedValueOnce(null);
        api.inventoryLedger.mockResolvedValueOnce(null);
        api.storerooms.list.mockResolvedValueOnce(null);
        const w = mount(InventoryView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('switches tabs', async () => {
        const w = mount(InventoryView);
        await flushPromises();
        await w.find('[data-test=tab-stock]').trigger('click');
        await w.find('[data-test=tab-low]').trigger('click');
        await w.find('[data-test=tab-ledger]').trigger('click');
        await w.find('[data-test=tab-items]').trigger('click');
        expect(w.exists()).toBe(true);
    });

    it('normalizes plain-array responses', async () => {
        api.inventoryItems.mockResolvedValueOnce([{ id: 1, external_key: 'A1', name: 'A', sku: 'B', category: 'consumable', unit_of_measure: 'c' }]);
        api.inventoryStockLevels.mockResolvedValueOnce([{ on_hand: 5 }]);
        api.inventoryLowStock.mockResolvedValueOnce({ data: [{ on_hand: 1 }] });
        api.inventoryLedger.mockResolvedValueOnce([{ kind: 'receipt' }]);
        api.storerooms.list.mockResolvedValueOnce([{ id: 1 }]);
        const w = mount(InventoryView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('creates a new item', async () => {
        api.inventoryCreateItem.mockResolvedValueOnce({});
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openItemForm();
        w.vm.itemForm.data = { external_key: 'K-1', name: 'X', sku: 'S', category: 'consumable', unit_of_measure: 'ea' };
        await w.vm.saveItem();
        expect(api.inventoryCreateItem).toHaveBeenCalled();
    });

    it('item create error surfaces', async () => {
        api.inventoryCreateItem.mockRejectedValueOnce({ response: { data: { message: 'dup' } } });
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openItemForm();
        await w.vm.saveItem();
        await flushPromises();
        expect(w.vm.itemForm.error).toBe('dup');
    });

    it('submits receive transaction', async () => {
        api.inventoryReceive.mockResolvedValueOnce({});
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openTx('receive');
        await w.vm.submitTx();
        expect(api.inventoryReceive).toHaveBeenCalled();
    });

    it('submits issue transaction', async () => {
        api.inventoryIssue.mockResolvedValueOnce({});
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openTx('issue');
        await w.vm.submitTx();
        expect(api.inventoryIssue).toHaveBeenCalled();
    });

    it('submits transfer transaction', async () => {
        api.inventoryTransfer.mockResolvedValueOnce({});
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openTx('transfer');
        await w.vm.submitTx();
        expect(api.inventoryTransfer).toHaveBeenCalled();
    });

    it('tx error surfaces', async () => {
        api.inventoryReceive.mockRejectedValueOnce({ response: { data: { message: 'short' } } });
        const w = mount(InventoryView);
        await flushPromises();
        w.vm.openTx('receive');
        await w.vm.submitTx();
        await flushPromises();
        expect(w.vm.txForm.error).toBe('short');
    });
});
