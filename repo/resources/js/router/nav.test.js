import { describe, it, expect } from 'vitest';
import { NAV, navFor } from './nav.js';

describe('nav', () => {
    it('exports NAV array', () => {
        expect(NAV.length).toBeGreaterThan(5);
        expect(NAV.every((x) => typeof x.to === 'string' && typeof x.label === 'string')).toBe(true);
    });

    it('returns empty array when no user', () => {
        expect(navFor(null)).toEqual([]);
        expect(navFor(undefined)).toEqual([]);
    });

    it('filters by role', () => {
        const admin = navFor({ role: 'system_admin' });
        expect(admin.some((x) => x.to === '/users')).toBe(true);

        const tech = navFor({ role: 'technician_doctor' });
        expect(tech.some((x) => x.to === '/users')).toBe(false);
        expect(tech.some((x) => x.to === '/rentals')).toBe(true);
    });

    it('shows null-role entries to everyone', () => {
        const editor = navFor({ role: 'content_editor' });
        expect(editor.some((x) => x.to === '/dashboard')).toBe(true);
        expect(editor.some((x) => x.to === '/content')).toBe(true);
    });
});
