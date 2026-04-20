import { config } from '@vue/test-utils';

// Ensure a working localStorage in jsdom for code paths that rely on it.
if (typeof globalThis.localStorage === 'undefined' || typeof globalThis.localStorage.getItem !== 'function') {
    const store = new Map();
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, String(v)),
            removeItem: (k) => store.delete(k),
            clear: () => store.clear(),
        },
    });
}

// Silence warnings about injected globals during tests.
config.global.stubs = {
    'router-link': { template: '<a><slot /></a>', props: ['to'] },
    'router-view': { template: '<div><slot /></div>' },
};
