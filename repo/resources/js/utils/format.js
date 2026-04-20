export function formatCurrency(value, currency = 'USD', locale = 'en-US') {
    if (value === null || value === undefined || value === '') return '—';
    const num = Number(value);
    if (!Number.isFinite(num)) return '—';
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(num);
}

export function formatDate(value, locale = 'en-US') {
    if (!value) return '—';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString(locale, { year: 'numeric', month: 'short', day: '2-digit' });
}

export function formatDateTime(value, locale = 'en-US') {
    if (!value) return '—';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(locale, {
        year: 'numeric', month: 'short', day: '2-digit',
        hour: '2-digit', minute: '2-digit',
    });
}

export function countdown(target, now = new Date()) {
    if (!target) return { expired: false, label: '—' };
    const end = target instanceof Date ? target : new Date(target);
    if (Number.isNaN(end.getTime())) return { expired: false, label: '—' };
    const ms = end.getTime() - (now instanceof Date ? now.getTime() : new Date(now).getTime());
    const expired = ms < 0;
    const abs = Math.abs(ms);
    const hours = Math.floor(abs / 3_600_000);
    const mins = Math.floor((abs % 3_600_000) / 60_000);
    const label = `${hours}h ${mins}m`;
    return { expired, label };
}

export function maskPhone(raw) {
    if (!raw) return '';
    const digits = String(raw).replace(/\D/g, '');
    if (digits.length < 4) return '***-***-****';
    const last4 = digits.slice(-4);
    const area = digits.length >= 10 ? digits.slice(-10, -7) : '***';
    return `(${area}) ***-${last4}`;
}
