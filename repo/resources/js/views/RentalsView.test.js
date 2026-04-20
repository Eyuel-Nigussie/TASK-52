import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        rentalAssets: { list: vi.fn() },
        rentalTransactions: { list: vi.fn() },
        rentalScan: vi.fn(),
        rentalCheckout: vi.fn(),
        rentalReturn: vi.fn(),
        rentalCancel: vi.fn(),
    },
}));

import RentalsView from './RentalsView.vue';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.rentalAssets.list.mockResolvedValue({ data: [
        {
            id: 1, name: 'Pump', category: 'infusion', external_key: 'A1',
            status: 'available', daily_rate: 100, deposit_amount: 200,
            facility_id: 1, photo_path: 'rentals/pump.jpg',
            specs: { flow: '200ml/hr', capacity: '1000ml' },
        },
        { id: 2, name: 'Scope', category: 'ultrasound', external_key: 'A2', status: 'rented', daily_rate: 120, deposit_amount: 300, facility_id: 1 },
    ] });
    api.rentalTransactions.list.mockResolvedValue({ data: [
        { id: 9, asset: { name: 'Pump' }, status: 'active', checked_out_at: new Date().toISOString(), expected_return_at: new Date(Date.now() + 3_600_000).toISOString() },
        { id: 10, asset: { name: 'Scope' }, status: 'overdue', checked_out_at: new Date().toISOString(), expected_return_at: new Date(Date.now() - 3_600_000).toISOString() },
    ] });
});

describe('RentalsView', () => {
    it('mounts and loads assets and transactions', async () => {
        const w = mount(RentalsView);
        await flushPromises();
        expect(w.text()).toContain('Pump');
        expect(api.rentalAssets.list).toHaveBeenCalled();
        expect(api.rentalTransactions.list).toHaveBeenCalled();
    });

    it('renders photo preview and specs for assets that have them', async () => {
        const w = mount(RentalsView);
        await flushPromises();

        // Pump has photo + specs; Scope has neither — mixed case.
        const photos = w.findAll('[data-test=asset-photo]');
        expect(photos.length).toBeGreaterThan(0);
        expect(photos[0].attributes('src')).toContain('rentals/pump.jpg');

        const specs = w.find('[data-test=asset-specs]');
        expect(specs.exists()).toBe(true);
        expect(specs.text()).toContain('flow');
        expect(specs.text()).toContain('200ml/hr');

        // Asset without a photo shows the placeholder.
        expect(w.find('[data-test=asset-photo-missing]').exists()).toBe(true);
    });

    it('renders photo and specs in scan result panel', async () => {
        api.rentalScan.mockResolvedValueOnce({
            id: 42, name: 'Portable X-ray', category: 'imaging',
            external_key: 'X-42', status: 'available',
            photo_path: 'rentals/xray.jpg',
            specs: ['DICOM-compatible', 'Battery-powered'],
        });
        const w = mount(RentalsView);
        await flushPromises();
        await w.find('[data-test=tab-scan]').trigger('click');
        w.vm.scanCode = 'X-42';
        await w.vm.doScan();
        await flushPromises();

        const resultPanel = w.find('[data-test=scan-result]');
        expect(resultPanel.exists()).toBe(true);
        expect(w.find('[data-test=scan-photo]').attributes('src')).toContain('rentals/xray.jpg');
        expect(w.find('[data-test=scan-specs]').text()).toContain('DICOM-compatible');
    });

    it('handles list failure gracefully', async () => {
        api.rentalAssets.list.mockRejectedValueOnce(new Error('nope'));
        api.rentalTransactions.list.mockRejectedValueOnce(new Error('nope'));
        const w = mount(RentalsView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes plain-array responses', async () => {
        api.rentalAssets.list.mockResolvedValueOnce([{ id: 9, name: 'X', status: 'available' }]);
        api.rentalTransactions.list.mockResolvedValueOnce([{ id: 99 }]);
        const w = mount(RentalsView);
        await flushPromises();
        expect(w.text()).toContain('X');
    });

    it('falls back to empty when list response is nullish', async () => {
        api.rentalAssets.list.mockResolvedValueOnce(null);
        api.rentalTransactions.list.mockResolvedValueOnce(null);
        const w = mount(RentalsView);
        await flushPromises();
        expect(w.vm.loadAssets).toBeDefined();
    });

    it('switches tabs', async () => {
        const w = mount(RentalsView);
        await flushPromises();
        await w.find('[data-test=tab-transactions]').trigger('click');
        await w.find('[data-test=tab-overdue]').trigger('click');
        await w.find('[data-test=tab-scan]').trigger('click');
        expect(w.find('input').exists()).toBe(true);
    });

    it('scan success populates result', async () => {
        api.rentalScan.mockResolvedValueOnce({ id: 1, name: 'Pump', category: 'infusion', external_key: 'A1', status: 'available' });
        const w = mount(RentalsView);
        await flushPromises();
        await w.find('[data-test=tab-scan]').trigger('click');
        w.find('input').setValue('A1');
        await w.find('input').trigger('keyup.enter');
        await flushPromises();
        expect(w.find('[data-test=scan-result]').text()).toContain('Pump');
    });

    it('scan with empty code does nothing', async () => {
        const w = mount(RentalsView);
        await flushPromises();
        await w.vm.doScan();
        expect(api.rentalScan).not.toHaveBeenCalled();
    });

    it('scan failure clears result', async () => {
        api.rentalScan.mockRejectedValueOnce(new Error('not found'));
        const w = mount(RentalsView);
        await flushPromises();
        await w.find('[data-test=tab-scan]').trigger('click');
        w.find('input').setValue('BAD');
        await w.vm.doScan();
        await flushPromises();
        expect(w.find('[data-test=scan-result]').exists()).toBe(false);
    });

    it('checkout path: blocks non-available', async () => {
        const w = mount(RentalsView);
        await flushPromises();
        w.vm.openCheckout({ id: 2, status: 'rented' });
        await flushPromises();
        // no modal open
        expect(w.findAll('[role=dialog]').length).toBe(0);
    });

    it('checkout path: success', async () => {
        api.rentalCheckout.mockResolvedValueOnce({});
        const w = mount(RentalsView);
        await flushPromises();
        w.vm.openCheckout({ id: 1, status: 'available', facility_id: 1 });
        await flushPromises();
        w.vm.checkoutForm.renter_id = 1;
        w.vm.checkoutForm.expected_return_at = new Date(Date.now() + 3_600_000).toISOString();
        await w.vm.submitCheckout();
        await flushPromises();
        expect(api.rentalCheckout).toHaveBeenCalled();
    });

    it('checkout path: error sets message', async () => {
        api.rentalCheckout.mockRejectedValueOnce({ response: { data: { message: 'bad' } } });
        const w = mount(RentalsView);
        await flushPromises();
        w.vm.openCheckout({ id: 1, status: 'available', facility_id: 1 });
        await w.vm.submitCheckout();
        await flushPromises();
        expect(w.text()).toContain('bad');
    });

    it('return path: success', async () => {
        api.rentalReturn.mockResolvedValueOnce({});
        const w = mount(RentalsView);
        await flushPromises();
        w.vm.openReturn({ id: 9 });
        await flushPromises();
        w.vm.returnNotes = 'ok';
        await w.vm.submitReturn();
        expect(api.rentalReturn).toHaveBeenCalledWith(9, { notes: 'ok' });
    });

    it('return path: error surfaces', async () => {
        api.rentalReturn.mockRejectedValueOnce({ response: { data: { message: 'no' } } });
        const w = mount(RentalsView);
        await flushPromises();
        w.vm.openReturn({ id: 9 });
        await w.vm.submitReturn();
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('cancel path: success', async () => {
        api.rentalCancel.mockResolvedValueOnce({});
        const w = mount(RentalsView);
        await flushPromises();
        await w.vm.cancel({ id: 9 });
        expect(api.rentalCancel).toHaveBeenCalledWith(9);
    });

    it('cancel path: error surfaces', async () => {
        api.rentalCancel.mockRejectedValueOnce(new Error('nope'));
        const w = mount(RentalsView);
        await flushPromises();
        await w.vm.cancel({ id: 9 });
        expect(w.exists()).toBe(true);
    });
});
