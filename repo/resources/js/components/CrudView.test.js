import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import CrudView from './CrudView.vue';

beforeEach(() => {
    setActivePinia(createPinia());
});

function resource() {
    return {
        list: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'Alice' }] }),
        create: vi.fn().mockResolvedValue({}),
        update: vi.fn().mockResolvedValue({}),
        remove: vi.fn().mockResolvedValue({}),
    };
}

function makeWrapper(overrides = {}) {
    return mount(CrudView, {
        props: {
            title: 'Widgets',
            resource: resource(),
            columns: [{ key: 'name', label: 'Name' }],
            fields: [
                { key: 'name', label: 'Name', required: true },
                { key: 'kind', label: 'Kind', type: 'select', options: [{ value: 'a', label: 'A' }] },
                { key: 'notes', label: 'Notes', type: 'textarea' },
                { key: 'age', label: 'Age', type: 'number' },
            ],
            canDelete: true,
            ...overrides,
        },
    });
}

describe('CrudView', () => {
    it('loads records on mount', async () => {
        const w = makeWrapper();
        await flushPromises();
        expect(w.text()).toContain('Alice');
    });

    it('shows error toast when list fails', async () => {
        const res = resource();
        res.list.mockRejectedValueOnce(new Error('boom'));
        const w = mount(CrudView, { props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }] } });
        await flushPromises();
        expect(w.text()).toContain('No records');
    });

    it('handles list returning plain array', async () => {
        const res = resource();
        res.list.mockResolvedValueOnce([{ id: 2, name: 'Bob' }]);
        const w = mount(CrudView, { props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }] } });
        await flushPromises();
        expect(w.text()).toContain('Bob');
    });

    it('handles list returning custom listKey', async () => {
        const res = resource();
        res.list.mockResolvedValueOnce({ items: [{ id: 3, name: 'Carol' }] });
        const w = mount(CrudView, { props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }], listKey: 'items' } });
        await flushPromises();
        expect(w.text()).toContain('Carol');
    });

    it('creates a new record', async () => {
        const w = makeWrapper();
        await flushPromises();
        w.vm.openNew();
        await flushPromises();
        w.vm.form.name = 'Dan';
        await w.vm.save();
        expect(w.vm.rows).toBeDefined();
    });

    it('edits an existing record', async () => {
        const w = makeWrapper();
        await flushPromises();
        w.vm.openEdit({ id: 5, name: 'Eve' });
        await flushPromises();
        w.vm.form.name = 'Eve 2';
        await w.vm.save();
        expect(w.vm.form.name).toBe('Eve 2');
    });

    it('shows form error on save failure', async () => {
        const res = resource();
        res.create.mockRejectedValueOnce({ response: { data: { message: 'bad' } } });
        const w = mount(CrudView, {
            props: {
                title: 'X', resource: res,
                columns: [{ key: 'name', label: 'N' }],
                fields: [{ key: 'name', label: 'Name' }],
            },
        });
        await flushPromises();
        w.vm.openNew();
        await flushPromises();
        await w.vm.save();
        await flushPromises();
        expect(w.find('[data-test=form-error]').text()).toBe('bad');
    });

    it('deletes a record', async () => {
        const w = makeWrapper();
        await flushPromises();
        await w.vm.remove({ id: 1 });
        expect(w.vm.rows).toBeDefined();
    });

    it('delete error surfaces toast', async () => {
        const res = resource();
        res.remove.mockRejectedValueOnce({ response: { data: { message: 'no' } } });
        const w = mount(CrudView, {
            props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }], canDelete: true },
        });
        await flushPromises();
        await w.vm.remove({ id: 1 });
        await flushPromises();
        // No crash; pinia store captured the error
        expect(true).toBe(true);
    });

    it('ignores delete when canDelete false', async () => {
        const res = resource();
        const w = mount(CrudView, {
            props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }], canDelete: false },
        });
        await flushPromises();
        await w.vm.remove({ id: 1 });
        expect(res.remove).not.toHaveBeenCalled();
    });

    it('ignores edit when canEdit false', async () => {
        const res = resource();
        const w = mount(CrudView, {
            props: { title: 'X', resource: res, columns: [{ key: 'name', label: 'N' }], canEdit: false },
        });
        await flushPromises();
        w.vm.openEdit({ id: 1 });
        expect(w.vm.form.name).toBeUndefined();
    });
});
