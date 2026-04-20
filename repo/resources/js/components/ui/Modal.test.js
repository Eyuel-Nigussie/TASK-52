import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Modal from './Modal.vue';

describe('Modal', () => {
    it('renders when open', () => {
        const w = mount(Modal, { props: { open: true, title: 'Hello' }, slots: { default: 'body' } });
        expect(w.text()).toContain('Hello');
        expect(w.text()).toContain('body');
    });

    it('is hidden when not open', () => {
        const w = mount(Modal, { props: { open: false } });
        expect(w.find('[role=dialog]').exists()).toBe(false);
    });

    it('emits close when backdrop clicked', async () => {
        const w = mount(Modal, { props: { open: true, title: 'X' } });
        await w.find('.absolute.inset-0').trigger('click');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('emits close from close button', async () => {
        const w = mount(Modal, { props: { open: true, title: 'X' } });
        await w.find('button[aria-label=Close]').trigger('click');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('omits title header when title is empty', () => {
        const w = mount(Modal, { props: { open: true } });
        expect(w.find('h3').exists()).toBe(false);
    });
});
