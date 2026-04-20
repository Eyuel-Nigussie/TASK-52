import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('@/api', () => ({
    api: {
        users:       { list: vi.fn().mockResolvedValue({ data: [] }) },
        facilities:  { list: vi.fn().mockResolvedValue({ data: [] }) },
        departments: { list: vi.fn().mockResolvedValue({ data: [] }) },
        doctors:     { list: vi.fn().mockResolvedValue({ data: [] }) },
        patients:    { list: vi.fn().mockResolvedValue({ data: [] }) },
        visits:      { list: vi.fn().mockResolvedValue({ data: [] }) },
    },
}));

import UsersView from './UsersView.vue';
import FacilitiesView from './FacilitiesView.vue';
import DepartmentsView from './DepartmentsView.vue';
import DoctorsView from './DoctorsView.vue';
import PatientsView from './PatientsView.vue';
import VisitsView from './VisitsView.vue';

beforeEach(() => {
    setActivePinia(createPinia());
});

const cases = [
    ['Users', UsersView],
    ['Facilities', FacilitiesView],
    ['Departments', DepartmentsView],
    ['Doctors', DoctorsView],
    ['Patients', PatientsView],
    ['Visits', VisitsView],
];

describe('admin views', () => {
    it.each(cases)('%s view renders and loads', async (label, View) => {
        const w = mount(View);
        await flushPromises();
        expect(w.text()).toContain(label);
    });
});
