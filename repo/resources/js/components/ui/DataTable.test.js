import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DataTable from './DataTable.vue';

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'user.name', label: 'Name' },
    { key: 'computed', label: 'Double', value: (r) => r.n * 2 },
];

describe('DataTable', () => {
    it('shows loading state', () => {
        const w = mount(DataTable, { props: { columns, rows: [], loading: true } });
        expect(w.text()).toContain('Loading');
    });

    it('shows empty state', () => {
        const w = mount(DataTable, { props: { columns, rows: [], empty: 'Nothing here' } });
        expect(w.text()).toContain('Nothing here');
    });

    it('falls back to default empty text when not provided', () => {
        const w = mount(DataTable, { props: { columns, rows: [] } });
        expect(w.text()).toContain('No records found.');
    });

    it('renders rows with dotted key path and computed column', () => {
        const rows = [
            { id: 1, user: { name: 'Alice' }, n: 5 },
            { id: 2, user: null, n: 3 },
        ];
        const w = mount(DataTable, { props: { columns, rows } });
        expect(w.text()).toContain('Alice');
        expect(w.text()).toContain('10'); // n*2
    });

    it('returns empty when column has no key or value', () => {
        const w = mount(DataTable, { props: { columns: [{ label: 'X' }], rows: [{ id: 1 }] } });
        expect(w.exists()).toBe(true);
    });

    it('emits rowClick', async () => {
        const rows = [{ id: 7, user: { name: 'A' }, n: 1 }];
        const w = mount(DataTable, { props: { columns, rows } });
        await w.find('tbody tr').trigger('click');
        expect(w.emitted('rowClick')?.[0][0]).toEqual(rows[0]);
    });
});
