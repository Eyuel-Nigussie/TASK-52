import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AppButton from './AppButton.vue';

describe('AppButton', () => {
    it('renders default variant and size', () => {
        const w = mount(AppButton, { slots: { default: 'Save' } });
        expect(w.text()).toContain('Save');
        expect(w.classes().some((c) => c.includes('bg-blue-600'))).toBe(true);
    });

    it('applies secondary, danger, ghost variants', () => {
        expect(mount(AppButton, { props: { variant: 'secondary' } }).classes().some((c) => c.includes('bg-gray-200'))).toBe(true);
        expect(mount(AppButton, { props: { variant: 'danger' } }).classes().some((c) => c.includes('bg-red-600'))).toBe(true);
        expect(mount(AppButton, { props: { variant: 'ghost' } }).classes().some((c) => c.includes('bg-transparent'))).toBe(true);
    });

    it('falls back to primary variant on unknown value', () => {
        expect(mount(AppButton, { props: { variant: 'mystery' } }).classes().some((c) => c.includes('bg-blue-600'))).toBe(true);
    });

    it('applies sm/lg sizes', () => {
        expect(mount(AppButton, { props: { size: 'sm' } }).classes().some((c) => c.includes('text-xs'))).toBe(true);
        expect(mount(AppButton, { props: { size: 'lg' } }).classes().some((c) => c.includes('text-base'))).toBe(true);
    });

    it('falls back to md size on unknown value', () => {
        expect(mount(AppButton, { props: { size: 'huge' } }).classes().some((c) => c.includes('text-sm'))).toBe(true);
    });

    it('emits click', async () => {
        const w = mount(AppButton);
        await w.trigger('click');
        expect(w.emitted('click')).toBeTruthy();
    });

    it('is disabled when disabled or loading', () => {
        expect(mount(AppButton, { props: { disabled: true } }).attributes('disabled')).toBeDefined();
        expect(mount(AppButton, { props: { loading: true } }).attributes('disabled')).toBeDefined();
    });

    it('shows spinner while loading', () => {
        const w = mount(AppButton, { props: { loading: true } });
        expect(w.find('[data-test=spinner]').exists()).toBe(true);
    });

    it('passes through type attr', () => {
        const w = mount(AppButton, { props: { type: 'submit' } });
        expect(w.attributes('type')).toBe('submit');
    });
});
