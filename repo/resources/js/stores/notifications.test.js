import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useNotificationsStore } from './notifications.js';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
});
afterEach(() => {
    vi.useRealTimers();
});

describe('notifications store', () => {
    it('pushes items with auto dismiss', () => {
        const s = useNotificationsStore();
        const id = s.push('info', 'hi', 1000);
        expect(s.items).toHaveLength(1);
        expect(s.items[0].id).toBe(id);
        vi.advanceTimersByTime(1000);
        expect(s.items).toHaveLength(0);
    });

    it('keeps item when ttl is 0', () => {
        const s = useNotificationsStore();
        s.push('info', 'sticky', 0);
        vi.advanceTimersByTime(10000);
        expect(s.items).toHaveLength(1);
    });

    it('shorthand helpers', () => {
        const s = useNotificationsStore();
        s.success('ok', 0);
        s.error('bad', 0);
        s.info('fyi', 0);
        expect(s.items.map((i) => i.kind)).toEqual(['success', 'error', 'info']);
    });

    it('dismiss removes by id', () => {
        const s = useNotificationsStore();
        const id = s.push('info', 'one', 0);
        s.push('info', 'two', 0);
        s.dismiss(id);
        expect(s.items).toHaveLength(1);
        expect(s.items[0].message).toBe('two');
    });

    it('clear empties items', () => {
        const s = useNotificationsStore();
        s.push('info', 'a', 0);
        s.push('info', 'b', 0);
        s.clear();
        expect(s.items).toHaveLength(0);
    });
});
