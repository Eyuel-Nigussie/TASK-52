import { describe, it, expect } from 'vitest';
import { formatCurrency, formatDate, formatDateTime, countdown, maskPhone } from './format.js';

describe('format.matrix currency cases', () => {
    const rows = [
        [0, '$0.00'],
        [1, '$1.00'],
        [12.3, '$12.30'],
        [99.99, '$99.99'],
        [1000, '$1,000.00'],
        [1000000.5, '$1,000,000.50'],
        ['42', '$42.00'],
        ['42.75', '$42.75'],
        [-1, '-$1.00'],
        [-1234.56, '-$1,234.56'],
    ];

    rows.forEach(([value, expected]) => {
        it(`formats ${String(value)} -> ${expected}`, () => {
            expect(formatCurrency(value)).toBe(expected);
        });
    });

    const invalidRows = [null, undefined, '', 'abc', Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY];
    invalidRows.forEach((value) => {
        it(`returns dash for invalid currency input ${String(value)}`, () => {
            expect(formatCurrency(value)).toBe('—');
        });
    });
});

describe('format.matrix date cases', () => {
    const validDates = [
        '2026-01-01',
        '2026-02-14',
        '2026-03-31',
        '2026-04-20',
        '2026-05-15T10:30:00Z',
        '2026-12-31T23:59:00Z',
    ];

    validDates.forEach((value) => {
        it(`formats date ${value}`, () => {
            expect(formatDate(value)).not.toBe('—');
        });
    });

    ['bad-date', '13/13/2026', ''].forEach((value) => {
        it(`date fallback for ${JSON.stringify(value)}`, () => {
            expect(formatDate(value)).toBe('—');
        });
    });

    [
        '2026-01-01T00:00:00Z',
        '2026-06-20T09:15:00Z',
        '2026-12-31T23:59:59Z',
    ].forEach((value) => {
        it(`formats datetime ${value}`, () => {
            expect(formatDateTime(value)).not.toBe('—');
        });
    });

    ['bad-datetime', null, undefined].forEach((value) => {
        it(`datetime fallback for ${String(value)}`, () => {
            expect(formatDateTime(value)).toBe('—');
        });
    });
});

describe('format.matrix countdown cases', () => {
    const now = new Date('2026-04-20T12:00:00Z');

    [
        ['2026-04-20T12:00:00Z', false, '0h 0m'],
        ['2026-04-20T12:30:00Z', false, '0h 30m'],
        ['2026-04-20T13:15:00Z', false, '1h 15m'],
        ['2026-04-20T14:00:00Z', false, '2h 0m'],
        ['2026-04-20T17:45:00Z', false, '5h 45m'],
        ['2026-04-20T11:59:00Z', true, '0h 1m'],
        ['2026-04-20T11:00:00Z', true, '1h 0m'],
        ['2026-04-20T10:15:00Z', true, '1h 45m'],
        ['2026-04-19T12:00:00Z', true, '24h 0m'],
    ].forEach(([target, expired, label]) => {
        it(`countdown ${target} => ${expired ? 'expired' : 'active'} ${label}`, () => {
            expect(countdown(target, now)).toEqual({ expired, label });
        });
    });

    [null, undefined, 'not-a-date'].forEach((target) => {
        it(`countdown fallback for ${String(target)}`, () => {
            expect(countdown(target, now)).toEqual({ expired: false, label: '—' });
        });
    });
});

describe('format.matrix phone masks', () => {
    [
        ['5551234567', '(555) ***-4567'],
        ['(555)1234567', '(555) ***-4567'],
        ['+1 (555) 123-4567', '(555) ***-4567'],
        ['2125550000', '(212) ***-0000'],
        ['9998887777', '(999) ***-7777'],
        ['12345', '(***) ***-2345'],
        ['12', '***-***-****'],
        ['', ''],
        [null, ''],
        [undefined, ''],
    ].forEach(([raw, expected]) => {
        it(`mask ${String(raw)} -> ${expected}`, () => {
            expect(maskPhone(raw)).toBe(expected);
        });
    });
});
