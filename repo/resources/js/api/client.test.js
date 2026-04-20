import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createApiClient, extractErrorMessage, TOKEN_STORAGE_KEY } from './client.js';

describe('TOKEN_STORAGE_KEY', () => {
    it('is exported as a non-empty string (reference constant)', () => {
        expect(typeof TOKEN_STORAGE_KEY).toBe('string');
        expect(TOKEN_STORAGE_KEY.length).toBeGreaterThan(0);
    });
});

describe('createApiClient', () => {
    it('attaches bearer token from getToken', async () => {
        const client = createApiClient({ getToken: () => 'abc123' });
        const req = await client.interceptors.request.handlers[0].fulfilled({ headers: {} });
        expect(req.headers.Authorization).toBe('Bearer abc123');
    });

    it('omits header when getToken returns null', async () => {
        const client = createApiClient({ getToken: () => null });
        const req = await client.interceptors.request.handlers[0].fulfilled({ headers: {} });
        expect(req.headers.Authorization).toBeUndefined();
    });

    it('omits header when no getToken provided', async () => {
        const client = createApiClient();
        const req = await client.interceptors.request.handlers[0].fulfilled({ headers: {} });
        expect(req.headers.Authorization).toBeUndefined();
    });

    it('calls onUnauthorized for 401 response', async () => {
        const onUnauthorized = vi.fn();
        const client = createApiClient({ onUnauthorized });
        const rejected = client.interceptors.response.handlers[0].rejected;
        await expect(rejected({ response: { status: 401 } })).rejects.toEqual({ response: { status: 401 } });
        expect(onUnauthorized).toHaveBeenCalled();
    });

    it('passes through non-401 errors', async () => {
        const onUnauthorized = vi.fn();
        const client = createApiClient({ onUnauthorized });
        const rejected = client.interceptors.response.handlers[0].rejected;
        await expect(rejected({ response: { status: 500 } })).rejects.toBeDefined();
        expect(onUnauthorized).not.toHaveBeenCalled();
    });

    it('handles 401 without an onUnauthorized callback', async () => {
        const client = createApiClient();
        const rejected = client.interceptors.response.handlers[0].rejected;
        await expect(rejected({ response: { status: 401 } })).rejects.toBeDefined();
    });

    it('success responses pass through', () => {
        const client = createApiClient();
        const success = client.interceptors.response.handlers[0].fulfilled;
        expect(success({ data: 1 })).toEqual({ data: 1 });
    });
});

describe('extractErrorMessage', () => {
    it('returns fallback for null', () => {
        expect(extractErrorMessage(null)).toBe('Request failed.');
        expect(extractErrorMessage(null, 'oops')).toBe('oops');
    });
    it('returns axios message when no response', () => {
        expect(extractErrorMessage({ message: 'network' })).toBe('network');
    });
    it('returns fallback when error has no message or response', () => {
        expect(extractErrorMessage({}, 'default')).toBe('default');
    });
    it('returns string payload directly', () => {
        expect(extractErrorMessage({ response: { data: 'boom' } })).toBe('boom');
    });
    it('returns message field from payload', () => {
        expect(extractErrorMessage({ response: { data: { message: 'nope' } } })).toBe('nope');
    });
    it('returns first validation error', () => {
        expect(extractErrorMessage({ response: { data: { errors: { email: ['bad'] } } } })).toBe('bad');
    });
    it('falls back when validation bag is empty', () => {
        expect(extractErrorMessage({ response: { data: { errors: {} } } }, 'x')).toBe('x');
    });
    it('falls back when validation value not an array', () => {
        expect(extractErrorMessage({ response: { data: { errors: { email: 'bad' } } } }, 'x')).toBe('x');
    });
});
