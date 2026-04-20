import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import Toasts from './Toasts.vue';
import { useNotificationsStore } from '@/stores/notifications';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('Toasts', () => {
    it('renders notifications', () => {
        const notes = useNotificationsStore();
        notes.push('success', 'Saved!', 0);
        notes.push('error', 'Oops', 0);
        notes.push('info', 'FYI', 0);
        notes.push('mystery', 'weird', 0);
        const w = mount(Toasts);
        expect(w.text()).toContain('Saved!');
        expect(w.text()).toContain('Oops');
        expect(w.text()).toContain('FYI');
        expect(w.text()).toContain('weird');
    });

    it('dismisses a toast on click', async () => {
        const notes = useNotificationsStore();
        notes.push('success', 'A', 0);
        const w = mount(Toasts);
        await w.find('[role=status]').trigger('click');
        expect(notes.items).toHaveLength(0);
    });
});
