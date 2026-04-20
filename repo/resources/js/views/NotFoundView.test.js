import { describe, it, expect } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import NotFoundView from './NotFoundView.vue';

describe('NotFoundView', () => {
    it('routes to dashboard when button clicked', async () => {
        const router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/', component: NotFoundView },
            { path: '/dashboard', component: { template: '<div>d</div>' } },
        ]});
        router.push('/');
        await router.isReady();
        const w = mount(NotFoundView, { global: { plugins: [router] } });
        await w.find('button').trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.path).toBe('/dashboard');
    });
});
