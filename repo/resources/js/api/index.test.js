import { describe, it, expect, vi, beforeEach } from 'vitest';
import { api, initApi, getClient, setClient, resetClient } from './index.js';

let client;

function makeClient() {
    return {
        get: vi.fn(() => Promise.resolve({ data: { ok: true } })),
        post: vi.fn(() => Promise.resolve({ data: { ok: true } })),
        put: vi.fn(() => Promise.resolve({ data: { ok: true } })),
        delete: vi.fn(() => Promise.resolve({ data: { ok: true } })),
    };
}

beforeEach(() => {
    resetClient();
    client = makeClient();
    setClient(client);
});

describe('client lifecycle', () => {
    it('initApi creates a client and registers callback', () => {
        resetClient();
        const onUnauth = vi.fn();
        const c = initApi({ onUnauthorized: onUnauth });
        expect(c).toBeDefined();
        expect(getClient()).toBe(c);
    });

    it('getClient lazily creates client when none set', () => {
        resetClient();
        const c = getClient();
        expect(c).toBeDefined();
        expect(getClient()).toBe(c);
    });

    it('setClient replaces the instance', () => {
        const c2 = makeClient();
        setClient(c2);
        expect(getClient()).toBe(c2);
    });
});

describe('auth endpoints', () => {
    it('login posts credentials', async () => {
        client.post.mockResolvedValueOnce({ data: { token: 't', user: {} } });
        await api.login({ username: 'u', password: 'p' });
        expect(client.post).toHaveBeenCalledWith('/auth/login', { username: 'u', password: 'p' });
    });
    it('logout posts', async () => {
        await api.logout();
        expect(client.post).toHaveBeenCalledWith('/auth/logout');
    });
    it('me gets /auth/me', async () => {
        await api.me();
        expect(client.get).toHaveBeenCalledWith('/auth/me');
    });
    it('changePassword posts payload', async () => {
        await api.changePassword({ current: 'a', new: 'b' });
        expect(client.post).toHaveBeenCalledWith('/auth/change-password', { current: 'a', new: 'b' });
    });
    it('captchaStatus passes username as query', async () => {
        await api.captchaStatus('bob');
        expect(client.get).toHaveBeenCalledWith('/auth/captcha-status', { params: { username: 'bob' } });
    });
    it('refreshSession posts to /auth/refresh', async () => {
        await api.refreshSession();
        expect(client.post).toHaveBeenCalledWith('/auth/refresh');
    });
});

describe('resource wrappers', () => {
    it('list/get/create/update/remove thread through client', async () => {
        await api.facilities.list({ a: 1 });
        expect(client.get).toHaveBeenCalledWith('/facilities', { params: { a: 1 } });
        await api.facilities.get(7, { b: 2 });
        expect(client.get).toHaveBeenCalledWith('/facilities/7', { params: { b: 2 } });
        await api.facilities.create({ name: 'X' });
        expect(client.post).toHaveBeenCalledWith('/facilities', { name: 'X' });
        await api.facilities.update(9, { name: 'Y' });
        expect(client.put).toHaveBeenCalledWith('/facilities/9', { name: 'Y' });
        await api.facilities.remove(9);
        expect(client.delete).toHaveBeenCalledWith('/facilities/9');
    });
});

describe('rental specific endpoints', () => {
    it('rentalScan passes code param', async () => {
        await api.rentalScan('ABC');
        expect(client.get).toHaveBeenCalledWith('/rental-assets/scan', { params: { code: 'ABC' } });
    });
    it('rentalCheckout posts payload', async () => {
        await api.rentalCheckout({ asset_id: 1 });
        expect(client.post).toHaveBeenCalledWith('/rental-transactions/checkout', { asset_id: 1 });
    });
    it('rentalReturn posts id path', async () => {
        await api.rentalReturn(7, { notes: 'n' });
        expect(client.post).toHaveBeenCalledWith('/rental-transactions/7/return', { notes: 'n' });
    });
    it('rentalCancel posts', async () => {
        await api.rentalCancel(7);
        expect(client.post).toHaveBeenCalledWith('/rental-transactions/7/cancel');
    });
    it('rentalOverdue gets', async () => {
        await api.rentalOverdue();
        expect(client.get).toHaveBeenCalledWith('/rental-transactions/overdue');
    });
});

describe('inventory specific endpoints', () => {
    it('all inventory endpoints call expected URL', async () => {
        await api.inventoryItems({ q: 'a' });
        expect(client.get).toHaveBeenCalledWith('/inventory/items', { params: { q: 'a' } });
        await api.inventoryCreateItem({ name: 'x' });
        expect(client.post).toHaveBeenCalledWith('/inventory/items', { name: 'x' });
        await api.inventoryUpdateItem(2, { name: 'y' });
        expect(client.put).toHaveBeenCalledWith('/inventory/items/2', { name: 'y' });
        await api.inventoryReceive({ q: 1 });
        expect(client.post).toHaveBeenCalledWith('/inventory/receive', { q: 1 });
        await api.inventoryIssue({ q: 1 });
        expect(client.post).toHaveBeenCalledWith('/inventory/issue', { q: 1 });
        await api.inventoryTransfer({ q: 1 });
        expect(client.post).toHaveBeenCalledWith('/inventory/transfer', { q: 1 });
        await api.inventoryStockLevels({ item_id: 3 });
        expect(client.get).toHaveBeenCalledWith('/inventory/stock-levels', { params: { item_id: 3 } });
        await api.inventoryLowStock();
        expect(client.get).toHaveBeenCalledWith('/inventory/low-stock-alerts');
        await api.inventoryLedger({ item_id: 3 });
        expect(client.get).toHaveBeenCalledWith('/inventory/ledger', { params: { item_id: 3 } });
    });
});

describe('stocktake endpoints', () => {
    it('wires all stocktake endpoints', async () => {
        await api.stocktakes({ status: 'open' });
        expect(client.get).toHaveBeenCalledWith('/stocktake', { params: { status: 'open' } });
        await api.stocktakeStart({ storeroom_id: 1 });
        expect(client.post).toHaveBeenCalledWith('/stocktake/start', { storeroom_id: 1 });
        await api.stocktakeShow(5);
        expect(client.get).toHaveBeenCalledWith('/stocktake/5');
        await api.stocktakeAddEntry(5, { item_id: 1 });
        expect(client.post).toHaveBeenCalledWith('/stocktake/5/entries', { item_id: 1 });
        await api.stocktakeApproveEntry(5, 9);
        expect(client.post).toHaveBeenCalledWith('/stocktake/5/entries/9/approve');
        await api.stocktakeClose(5);
        expect(client.post).toHaveBeenCalledWith('/stocktake/5/close');
    });
});

describe('content endpoints', () => {
    it('wires content workflow', async () => {
        await api.contentSubmit(3);
        expect(client.post).toHaveBeenCalledWith('/content/3/submit-review');
        await api.contentApprove(3);
        expect(client.post).toHaveBeenCalledWith('/content/3/approve');
        await api.contentPublish(3);
        expect(client.post).toHaveBeenCalledWith('/content/3/publish');
        await api.contentRollback(3, 2);
        expect(client.post).toHaveBeenCalledWith('/content/3/rollback', { version: 2 });
        await api.contentVersions(3);
        expect(client.get).toHaveBeenCalledWith('/content/3/versions');
    });
});

describe('review endpoints', () => {
    it('wires review workflow', async () => {
        await api.reviewDashboard({ facility_id: 1 });
        expect(client.get).toHaveBeenCalledWith('/reviews/dashboard', { params: { facility_id: 1 } });
        await api.reviewSubmit(5, { rating: 5 });
        expect(client.post).toHaveBeenCalledWith('/reviews/visits/5/submit', { rating: 5 });
        await api.reviewPublish(5);
        expect(client.post).toHaveBeenCalledWith('/reviews/5/publish');
        await api.reviewHide(5, 'abusive');
        expect(client.post).toHaveBeenCalledWith('/reviews/5/hide', { reason: 'abusive' });
        await api.reviewRespond(5, 'sorry');
        expect(client.post).toHaveBeenCalledWith('/reviews/5/respond', { body: 'sorry' });
        await api.reviewAppeal(5, 'spam');
        expect(client.post).toHaveBeenCalledWith('/reviews/5/appeal', { reason: 'spam' });
    });
});

describe('merge endpoints', () => {
    it('wires merge approve/reject', async () => {
        await api.mergeApprove(4);
        expect(client.post).toHaveBeenCalledWith('/merge-requests/4/approve');
        await api.mergeReject(4);
        expect(client.post).toHaveBeenCalledWith('/merge-requests/4/reject');
    });
});

describe('all resource modules expose CRUD', () => {
    const names = [
        'facilities', 'departments', 'users', 'doctors', 'patients', 'visits',
        'rentalAssets', 'rentalTransactions', 'storerooms', 'serviceOrders',
        'content', 'reviews', 'mergeRequests', 'auditLogs',
    ];
    it.each(names)('%s has list/get/create/update/remove', (n) => {
        expect(typeof api[n].list).toBe('function');
        expect(typeof api[n].get).toBe('function');
        expect(typeof api[n].create).toBe('function');
        expect(typeof api[n].update).toBe('function');
        expect(typeof api[n].remove).toBe('function');
    });
});
