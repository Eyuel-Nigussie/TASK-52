import { createApiClient } from './client.js';

let clientInstance = null;
let unauthorizedHandler = null;

export function initApi({ getToken, onUnauthorized } = {}) {
    unauthorizedHandler = onUnauthorized;
    clientInstance = createApiClient({ getToken, onUnauthorized: (err) => unauthorizedHandler?.(err) });
    return clientInstance;
}

export function getClient() {
    if (!clientInstance) {
        clientInstance = createApiClient({ onUnauthorized: (err) => unauthorizedHandler?.(err) });
    }
    return clientInstance;
}

export function setClient(client) {
    clientInstance = client;
}

export function resetClient() {
    clientInstance = null;
    unauthorizedHandler = null;
}

const resource = (path) => ({
    list: (params) => getClient().get(path, { params }).then((r) => r.data),
    get: (id, params) => getClient().get(`${path}/${id}`, { params }).then((r) => r.data),
    create: (payload) => getClient().post(path, payload).then((r) => r.data),
    update: (id, payload) => getClient().put(`${path}/${id}`, payload).then((r) => r.data),
    remove: (id) => getClient().delete(`${path}/${id}`).then((r) => r.data),
});

export const api = {
    // Auth
    login: (credentials) => getClient().post('/auth/login', credentials).then((r) => r.data),
    logout: () => getClient().post('/auth/logout').then((r) => r.data),
    me: () => getClient().get('/auth/me').then((r) => r.data),
    refreshSession: () => getClient().post('/auth/refresh').then((r) => r.data),
    changePassword: (payload) => getClient().post('/auth/change-password', payload).then((r) => r.data),
    captchaStatus: (username) => getClient().get('/auth/captcha-status', { params: { username } }).then((r) => r.data),

    // Resources
    facilities: resource('/facilities'),
    departments: resource('/departments'),
    users: resource('/users'),
    doctors: resource('/doctors'),
    patients: resource('/patients'),
    visits: resource('/visits'),
    rentalAssets: resource('/rental-assets'),
    rentalTransactions: resource('/rental-transactions'),
    storerooms: resource('/storerooms'),
    serviceOrders: resource('/service-orders'),
    content: resource('/content'),
    reviews: resource('/reviews'),
    mergeRequests: resource('/merge-requests'),
    auditLogs: resource('/audit-logs'),

    // Rentals specific
    rentalScan: (code) => getClient().get('/rental-assets/scan', { params: { code } }).then((r) => r.data),
    rentalCheckout: (payload) => getClient().post('/rental-transactions/checkout', payload).then((r) => r.data),
    rentalReturn: (id, payload) => getClient().post(`/rental-transactions/${id}/return`, payload).then((r) => r.data),
    rentalCancel: (id) => getClient().post(`/rental-transactions/${id}/cancel`).then((r) => r.data),
    rentalOverdue: () => getClient().get('/rental-transactions/overdue').then((r) => r.data),

    // Inventory specific
    inventoryItems: (params) => getClient().get('/inventory/items', { params }).then((r) => r.data),
    inventoryCreateItem: (payload) => getClient().post('/inventory/items', payload).then((r) => r.data),
    inventoryUpdateItem: (id, payload) => getClient().put(`/inventory/items/${id}`, payload).then((r) => r.data),
    inventoryReceive: (payload) => getClient().post('/inventory/receive', payload).then((r) => r.data),
    inventoryIssue: (payload) => getClient().post('/inventory/issue', payload).then((r) => r.data),
    inventoryTransfer: (payload) => getClient().post('/inventory/transfer', payload).then((r) => r.data),
    inventoryStockLevels: (params) => getClient().get('/inventory/stock-levels', { params }).then((r) => r.data),
    inventoryLowStock: () => getClient().get('/inventory/low-stock-alerts').then((r) => r.data),
    inventoryLedger: (params) => getClient().get('/inventory/ledger', { params }).then((r) => r.data),

    // Stocktake
    stocktakes: (params) => getClient().get('/stocktake', { params }).then((r) => r.data),
    stocktakeStart: (payload) => getClient().post('/stocktake/start', payload).then((r) => r.data),
    stocktakeShow: (id) => getClient().get(`/stocktake/${id}`).then((r) => r.data),
    stocktakeAddEntry: (id, payload) => getClient().post(`/stocktake/${id}/entries`, payload).then((r) => r.data),
    stocktakeApproveEntry: (sid, eid, reason) => getClient().post(`/stocktake/${sid}/entries/${eid}/approve`, { reason }).then((r) => r.data),
    stocktakeClose: (id) => getClient().post(`/stocktake/${id}/close`).then((r) => r.data),

    // Content workflow
    contentSubmit: (id) => getClient().post(`/content/${id}/submit-review`).then((r) => r.data),
    contentApprove: (id) => getClient().post(`/content/${id}/approve`).then((r) => r.data),
    contentPublish: (id) => getClient().post(`/content/${id}/publish`).then((r) => r.data),
    contentRollback: (id, version) => getClient().post(`/content/${id}/rollback`, { version }).then((r) => r.data),
    contentVersions: (id) => getClient().get(`/content/${id}/versions`).then((r) => r.data),

    // Reviews workflow
    reviewDashboard: (params) => getClient().get('/reviews/dashboard', { params }).then((r) => r.data),
    reviewDashboardBreakdown: (params) => getClient().get('/reviews/dashboard/breakdown', { params }).then((r) => r.data),
    reviewSubmit: (visitId, payload) => getClient().post(`/reviews/visits/${visitId}/submit`, payload).then((r) => r.data),
    reviewPublish: (id) => getClient().post(`/reviews/${id}/publish`).then((r) => r.data),
    reviewHide: (id, reason) => getClient().post(`/reviews/${id}/hide`, { reason }).then((r) => r.data),
    reviewRespond: (id, body) => getClient().post(`/reviews/${id}/respond`, { body }).then((r) => r.data),
    reviewAppeal: (id, reason) => getClient().post(`/reviews/${id}/appeal`, { reason }).then((r) => r.data),

    // Merge requests
    mergeApprove: (id) => getClient().post(`/merge-requests/${id}/approve`).then((r) => r.data),
    mergeReject: (id) => getClient().post(`/merge-requests/${id}/reject`).then((r) => r.data),
};
