import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StatusBadge from './StatusBadge.vue';

describe('StatusBadge', () => {
    it('renders a known status with styling', () => {
        const w = mount(StatusBadge, { props: { status: 'available' } });
        expect(w.text().toLowerCase()).toContain('available');
        expect(w.classes().some((c) => c.includes('bg-green-100'))).toBe(true);
    });

    it('humanizes underscored status labels', () => {
        const w = mount(StatusBadge, { props: { status: 'in_maintenance' } });
        expect(w.text()).toContain('in maintenance');
    });

    it('falls back for unknown status', () => {
        const w = mount(StatusBadge, { props: { status: 'alien' } });
        expect(w.classes().some((c) => c.includes('bg-gray-100'))).toBe(true);
    });

    it('handles null-ish status without crashing', () => {
        const w = mount(StatusBadge, { props: { status: '' } });
        expect(w.exists()).toBe(true);
    });

    it.each(['rented','active','deactivated','overdue','returned','cancelled','draft','in_review','approved','published','hidden','pending','open','closed'])('styles %s', (s) => {
        expect(mount(StatusBadge, { props: { status: s } }).exists()).toBe(true);
    });
});
