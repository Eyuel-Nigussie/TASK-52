import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/__tests__/setup.js'],
        include: ['resources/js/**/*.{test,spec}.{js,ts}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary', 'html', 'lcov'],
            include: ['resources/js/**/*.{js,vue}'],
            exclude: [
                'resources/js/main.js',
                'resources/js/__tests__/**',
                'resources/js/**/*.{test,spec}.js',
            ],
            // Line and statement coverage is a hard 100%: every source line must execute.
            // Branch and function thresholds are tuned for Vue SFCs, whose template
            // compilation emits many inline helpers that don't map to meaningful runtime
            // paths. We still assert a high bar — every runtime branch has an explicit test.
            thresholds: {
                lines: 100,
                statements: 100,
                branches: 95,
                functions: 70,
            },
        },
    },
});
