import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { useAuthStore } from '@/stores/auth';

vi.mock('@/api', () => ({
    api: {
        reviews: { list: vi.fn() },
        reviewDashboard: vi.fn(),
        reviewDashboardBreakdown: vi.fn(),
        reviewRespond: vi.fn(),
        reviewHide: vi.fn(),
        reviewAppeal: vi.fn(),
        reviewPublish: vi.fn(),
    },
}));

import ReviewsView from './ReviewsView.vue';
import { api } from '@/api';

function seedAuth({ facility_id = 42 } = {}) {
    const auth = useAuthStore();
    auth.user = { id: 1, role: 'clinic_manager', facility_id };
    auth.token = 'test-token';
    return auth;
}

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    api.reviews.list.mockResolvedValue({ data: [{ id: 1, rating: 5, status: 'pending', body: 'great' }] });
    // Backend contract: average_rating, negative_review_rate, median_response_time_hours.
    api.reviewDashboard.mockResolvedValue({
        total: 10,
        average_rating: 4.27,
        negative_review_rate: 12.5,
        median_response_time_hours: 3.4,
    });
    api.reviewDashboardBreakdown.mockResolvedValue({
        overall: { total: 10, average_rating: 4.27, negative_review_rate: 12.5, median_response_time_hours: 3.4 },
        by_facility: [
            { facility_id: 42, total: 10, average_rating: 4.27, negative_review_rate: 12.5, median_response_time_hours: 3.4 },
        ],
        by_provider: [
            { doctor_id: 7, total: 5, average_rating: 4.5, negative_review_rate: 10, median_response_time_hours: 2.1 },
        ],
    });
});

describe('ReviewsView', () => {
    it('loads reviews and dashboard', async () => {
        seedAuth();
        const w = mount(ReviewsView);
        await flushPromises();
        expect(w.text()).toContain('great');
    });

    it('sends facility_id to dashboard endpoint from auth store', async () => {
        seedAuth({ facility_id: 7 });
        mount(ReviewsView);
        await flushPromises();
        expect(api.reviewDashboard).toHaveBeenCalledWith({ facility_id: 7 });
    });

    it('renders dashboard using backend contract keys', async () => {
        seedAuth();
        const w = mount(ReviewsView);
        await flushPromises();
        await w.find('[data-test=tab-dashboard]').trigger('click');
        expect(w.find('[data-test=dashboard-cards]').exists()).toBe(true);
        expect(w.find('[data-test=card-average-rating]').text()).toContain('4.27');
        expect(w.find('[data-test=card-negative-rate]').text()).toContain('12.5%');
        expect(w.find('[data-test=card-median-response]').text()).toContain('3.4h');
    });

    it('dashboard handles null data', async () => {
        seedAuth();
        api.reviewDashboard.mockResolvedValueOnce(null);
        const w = mount(ReviewsView);
        await flushPromises();
        await w.find('[data-test=tab-dashboard]').trigger('click');
        expect(w.text()).toContain('—');
    });

    it('normalizes plain array response', async () => {
        seedAuth();
        api.reviews.list.mockResolvedValueOnce([{ id: 2, rating: 2, status: 'pending', body: 'ok' }]);
        const w = mount(ReviewsView);
        await flushPromises();
        expect(w.text()).toContain('ok');
    });

    it('publish action', async () => {
        seedAuth();
        api.reviewPublish.mockResolvedValueOnce({});
        const w = mount(ReviewsView);
        await flushPromises();
        w.vm.openAction('publish', { id: 1 });
        await w.vm.submitAction();
        expect(api.reviewPublish).toHaveBeenCalledWith(1);
    });

    it('respond action', async () => {
        seedAuth();
        api.reviewRespond.mockResolvedValueOnce({});
        const w = mount(ReviewsView);
        await flushPromises();
        w.vm.openAction('respond', { id: 1 });
        w.vm.modal.text = 'thanks';
        await w.vm.submitAction();
        expect(api.reviewRespond).toHaveBeenCalledWith(1, 'thanks');
    });

    it('hide action', async () => {
        seedAuth();
        api.reviewHide.mockResolvedValueOnce({});
        const w = mount(ReviewsView);
        await flushPromises();
        w.vm.openAction('hide', { id: 1 });
        w.vm.modal.text = 'abusive';
        await w.vm.submitAction();
        expect(api.reviewHide).toHaveBeenCalledWith(1, 'abusive');
    });

    it('appeal action', async () => {
        seedAuth();
        api.reviewAppeal.mockResolvedValueOnce({});
        const w = mount(ReviewsView);
        await flushPromises();
        w.vm.openAction('appeal', { id: 1 });
        w.vm.modal.text = 'spam';
        await w.vm.submitAction();
        expect(api.reviewAppeal).toHaveBeenCalledWith(1, 'spam');
    });

    it('action error surfaces', async () => {
        seedAuth();
        api.reviewHide.mockRejectedValueOnce({ response: { data: { message: 'nope' } } });
        const w = mount(ReviewsView);
        await flushPromises();
        w.vm.openAction('hide', { id: 1 });
        await w.vm.submitAction();
        await flushPromises();
        expect(w.vm.modal.error).toBe('nope');
    });

    it('skips dashboard request when user has no facility', async () => {
        seedAuth({ facility_id: null });
        mount(ReviewsView);
        await flushPromises();
        expect(api.reviewDashboard).not.toHaveBeenCalled();
    });

    it('renders by-clinic and by-provider breakdown tables', async () => {
        seedAuth();
        const w = mount(ReviewsView);
        await flushPromises();
        await w.find('[data-test=tab-dashboard]').trigger('click');
        expect(w.find('[data-test=breakdown-by-facility]').exists()).toBe(true);
        expect(w.find('[data-test=breakdown-by-provider]').exists()).toBe(true);
        expect(w.find('[data-test=breakdown-by-facility]').text()).toContain('#42');
        expect(w.find('[data-test=breakdown-by-provider]').text()).toContain('#7');
    });
});
