import { describe, it, expect, vi } from 'vitest';
import { useAsync } from './useAsync.js';

describe('useAsync', () => {
    it('resolves and stores data', async () => {
        const fn = vi.fn().mockResolvedValue({ ok: true });
        const { run, loading, error, data } = useAsync(fn);
        const result = await run(1, 2);
        expect(result).toEqual({ ok: true });
        expect(data.value).toEqual({ ok: true });
        expect(loading.value).toBe(false);
        expect(error.value).toBeNull();
        expect(fn).toHaveBeenCalledWith(1, 2);
    });

    it('captures error message and rethrows', async () => {
        const fn = vi.fn().mockRejectedValue({ response: { data: { message: 'bad' } } });
        const { run, error, loading } = useAsync(fn);
        await expect(run()).rejects.toBeDefined();
        expect(error.value).toBe('bad');
        expect(loading.value).toBe(false);
    });

    it('resets error on subsequent success', async () => {
        let n = 0;
        const fn = vi.fn(() => { n++; return n === 1 ? Promise.reject(new Error('x')) : Promise.resolve('y'); });
        const { run, error } = useAsync(fn);
        await expect(run()).rejects.toThrow();
        expect(error.value).toBe('x');
        await run();
        expect(error.value).toBeNull();
    });
});
