import { describe, it, expect } from 'vitest';
import { formatCurrency, formatDate, formatDateTime, countdown, maskPhone } from './format.js';

describe('formatCurrency', () => {
    it('formats USD', () => {
        expect(formatCurrency(1234.5)).toMatch(/\$1,234\.50/);
    });
    it('handles non-numeric', () => {
        expect(formatCurrency('abc')).toBe('—');
        expect(formatCurrency(null)).toBe('—');
        expect(formatCurrency(NaN)).toBe('—');
    });
});

describe('formatDate', () => {
    it('formats Date objects', () => {
        expect(formatDate(new Date('2025-06-15T00:00:00Z'))).toMatch(/2025/);
    });
    it('parses strings', () => {
        expect(formatDate('2025-06-15')).toMatch(/2025/);
    });
    it('falls back for null', () => {
        expect(formatDate(null)).toBe('—');
        expect(formatDate(undefined)).toBe('—');
    });
    it('falls back for invalid', () => {
        expect(formatDate('not-a-date')).toBe('—');
    });
});

describe('formatDateTime', () => {
    it('formats with time', () => {
        const out = formatDateTime(new Date('2025-06-15T13:45:00'));
        expect(out).toMatch(/:/);
    });
    it('parses strings', () => {
        expect(formatDateTime('2025-06-15T13:45:00')).toMatch(/2025/);
    });
    it('falls back for null', () => {
        expect(formatDateTime(null)).toBe('—');
    });
    it('falls back for invalid', () => {
        expect(formatDateTime('garbage')).toBe('—');
    });
});

describe('countdown', () => {
    const now = new Date('2025-06-15T12:00:00Z');
    it('returns future label', () => {
        const future = new Date('2025-06-15T14:30:00Z');
        expect(countdown(future, now)).toEqual({ expired: false, label: '2h 30m' });
    });
    it('returns past label with expired=true', () => {
        const past = new Date('2025-06-15T10:00:00Z');
        expect(countdown(past, now)).toEqual({ expired: true, label: '2h 0m' });
    });
    it('accepts string target', () => {
        expect(countdown('2025-06-15T14:00:00Z', now).label).toBe('2h 0m');
    });
    it('accepts string now', () => {
        expect(countdown('2025-06-15T14:00:00Z', '2025-06-15T12:00:00Z').label).toBe('2h 0m');
    });
    it('handles null target', () => {
        expect(countdown(null)).toEqual({ expired: false, label: '—' });
    });
    it('handles invalid date', () => {
        expect(countdown('nope')).toEqual({ expired: false, label: '—' });
    });
    it('defaults now to current time when omitted', () => {
        const res = countdown(new Date(Date.now() + 60_000));
        expect(res.expired).toBe(false);
    });
});

describe('maskPhone', () => {
    it('masks 10 digit numbers', () => {
        expect(maskPhone('555-123-4567')).toBe('(555) ***-4567');
    });
    it('handles formatted input', () => {
        expect(maskPhone('(212) 555 1234')).toBe('(212) ***-1234');
    });
    it('falls back for short strings', () => {
        expect(maskPhone('12')).toBe('***-***-****');
    });
    it('returns empty for empty input', () => {
        expect(maskPhone('')).toBe('');
        expect(maskPhone(null)).toBe('');
        expect(maskPhone(undefined)).toBe('');
    });
    it('handles less than 10 digits but at least 4', () => {
        expect(maskPhone('12345')).toBe('(***) ***-2345');
    });
});
