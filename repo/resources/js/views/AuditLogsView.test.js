import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        auditLogs: { list: vi.fn() },
    },
}));

import AuditLogsView from './AuditLogsView.vue';
import { api } from '@/api';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.auditLogs.list.mockResolvedValue({ data: [{ id: 1, event: 'login', user_id: 1, entity_type: 'User', entity_id: 1, ip_address: '10.0.0.1', created_at: new Date().toISOString() }] });
});

describe('AuditLogsView', () => {
    it('loads logs', async () => {
        const w = mount(AuditLogsView);
        await flushPromises();
        expect(w.text()).toContain('login');
    });

    it('handles load failure', async () => {
        api.auditLogs.list.mockRejectedValueOnce(new Error('x'));
        const w = mount(AuditLogsView);
        await flushPromises();
        expect(w.exists()).toBe(true);
    });

    it('normalizes array response', async () => {
        api.auditLogs.list.mockResolvedValueOnce([{ id: 2, event: 'update' }]);
        const w = mount(AuditLogsView);
        await flushPromises();
        expect(w.text()).toContain('update');
    });
});
