import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';

vi.mock('@/api', () => ({
    api: {
        reviewSubmit: vi.fn(),
    },
}));

import TabletReviewView from './TabletReviewView.vue';
import { api } from '@/api';

async function mountView() {
    const router = createRouter({ history: createMemoryHistory(), routes: [
        { path: '/tablet/reviews/:visitId', component: TabletReviewView },
    ]});
    router.push('/tablet/reviews/99');
    await router.isReady();
    return mount(TabletReviewView, { global: { plugins: [router] } });
}

// Turn a FormData instance (or an object masquerading as one) into a plain object
// so we can assert on fields without coupling to ordering.
function fdToObject(fd) {
    const obj = {};
    for (const [k, v] of fd.entries()) {
        if (k.endsWith('[]')) {
            const key = k.slice(0, -2);
            (obj[key] ||= []).push(v);
        } else {
            obj[k] = v;
        }
    }
    return obj;
}

// URL.createObjectURL is not defined in jsdom; stub it so the preview `src`
// binding doesn't throw when the component renders picked files.
beforeEach(() => {
    vi.clearAllMocks();
    if (typeof URL.createObjectURL !== 'function') {
        URL.createObjectURL = () => 'blob:preview';
    }
});

describe('TabletReviewView', () => {
    it('requires a rating before submit', async () => {
        const w = await mountView();
        await flushPromises();
        await w.find('[data-test=submit-review]').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Please choose a rating');
        expect(api.reviewSubmit).not.toHaveBeenCalled();
    });

    it('submits rating, tags, and body as multipart', async () => {
        api.reviewSubmit.mockResolvedValueOnce({ ok: true });
        const w = await mountView();
        await flushPromises();
        await w.find('[data-test=star-5]').trigger('click');
        await w.find('[data-test=tag-clean_facility]').trigger('click');
        await w.find('[data-test=review-body]').setValue('All good!');
        await w.find('[data-test=submitter-name]').setValue('Jane');
        await w.find('[data-test=submit-review]').trigger('click');
        await flushPromises();

        expect(api.reviewSubmit).toHaveBeenCalledTimes(1);
        const [visitId, fd] = api.reviewSubmit.mock.calls[0];
        expect(visitId).toBe('99');
        const payload = fdToObject(fd);
        expect(payload.rating).toBe('5');
        expect(payload.body).toBe('All good!');
        expect(payload.submitted_by_name).toBe('Jane');
        expect(payload.tags).toEqual(['clean_facility']);
        expect(w.text()).toContain('Thank you!');
    });

    it('omits submitter_name when empty (supports anonymous submission)', async () => {
        api.reviewSubmit.mockResolvedValueOnce({ ok: true });
        const w = await mountView();
        await flushPromises();
        await w.find('[data-test=star-4]').trigger('click');
        await w.find('[data-test=submit-review]').trigger('click');
        await flushPromises();

        const [, fd] = api.reviewSubmit.mock.calls[0];
        const payload = fdToObject(fd);
        expect(payload.rating).toBe('4');
        expect(payload.submitted_by_name).toBeUndefined();
    });

    it('attaches picked images (up to 5) to the multipart body', async () => {
        api.reviewSubmit.mockResolvedValueOnce({ ok: true });
        const w = await mountView();
        await flushPromises();
        await w.find('[data-test=star-5]').trigger('click');

        const files = [
            new File(['a'], 'a.jpg', { type: 'image/jpeg' }),
            new File(['b'], 'b.jpg', { type: 'image/jpeg' }),
            new File(['c'], 'c.jpg', { type: 'image/jpeg' }),
        ];
        const input = w.find('[data-test=review-images]').element;
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        await w.find('[data-test=review-images]').trigger('change');

        await w.find('[data-test=submit-review]').trigger('click');
        await flushPromises();

        const [, fd] = api.reviewSubmit.mock.calls[0];
        const payload = fdToObject(fd);
        expect(payload.images).toHaveLength(3);
        expect(payload.images[0].name).toBe('a.jpg');
    });

    it('caps images at 5 even when more are picked', async () => {
        const w = await mountView();
        await flushPromises();
        const files = [];
        for (let i = 0; i < 8; i++) files.push(new File(['x'], `x${i}.jpg`, { type: 'image/jpeg' }));
        const input = w.find('[data-test=review-images]').element;
        Object.defineProperty(input, 'files', { value: files, configurable: true });
        await w.find('[data-test=review-images]').trigger('change');
        expect(w.vm.images).toHaveLength(5);
    });

    it('shows error when api fails', async () => {
        api.reviewSubmit.mockRejectedValueOnce({ response: { data: { message: 'closed' } } });
        const w = await mountView();
        await flushPromises();
        await w.find('[data-test=star-3]').trigger('click');
        await w.find('[data-test=submit-review]').trigger('click');
        await flushPromises();
        expect(w.find('[role=alert]').text()).toBe('closed');
    });
});
