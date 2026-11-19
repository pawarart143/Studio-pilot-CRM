import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import isoWeek from 'dayjs/plugin/isoWeek';

dayjs.extend(relativeTime);
dayjs.extend(customParseFormat);
dayjs.extend(isoWeek);

export { dayjs };

/** Indian digit grouping: 1234567 → 12,34,567 */
export function inr(n: number | string | null | undefined, opts: { decimals?: boolean; symbol?: boolean } = {}) {
  const v = Number(n || 0);
  const hasDec = opts.decimals ?? Math.round(v) !== v;
  const s = new Intl.NumberFormat('en-IN', { minimumFractionDigits: hasDec ? 2 : 0, maximumFractionDigits: hasDec ? 2 : 0 }).format(v);
  return (opts.symbol === false ? '' : '₹') + s;
}

/** Compact INR: ₹4.2L, ₹1.3Cr */
export function inrShort(n: number | null | undefined) {
  const v = Number(n || 0);
  const a = Math.abs(v);
  if (a >= 1e7) return '₹' + (v / 1e7).toFixed(a >= 1e8 ? 0 : 1).replace(/\.0$/, '') + 'Cr';
  if (a >= 1e5) return '₹' + (v / 1e5).toFixed(a >= 1e6 ? 0 : 1).replace(/\.0$/, '') + 'L';
  if (a >= 1e3) return '₹' + (v / 1e3).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
  return '₹' + v;
}

export const fmtDate = (d?: string | null) => (d ? dayjs(d).format('DD-MM-YYYY') : '—');
export const fmtDateShort = (d?: string | null) => (d ? dayjs(d).format('DD MMM') : '—');
export const fmtDateLong = (d?: string | null) => (d ? dayjs(d).format('ddd, DD MMM YYYY') : '—');
export const fmtDateTime = (d?: string | null) => (d ? dayjs(d).format('DD-MM-YYYY, hh:mm A') : '—');
export const fmtTime = (t?: string | null) => (t ? dayjs('2000-01-01 ' + t).format('hh:mm A') : '');
export const fromNow = (d?: string | null) => (d ? dayjs(d).fromNow() : '');
export const monthLabel = (ym: string) => dayjs(ym + '-01').format('MMM YY');

/**
 * Every individual day of an event, start through end (inclusive).
 * A single-day event (no end date, or the same end date) returns one date.
 * `max` caps the list so a typo in the end date can never render thousands of rows;
 * use eventDayCount() for the real number of days.
 */
export function eventDays(start?: string | null, end?: string | null, max = 60): string[] {
  if (!start) return [];
  const s = dayjs(start);
  if (!s.isValid()) return [];
  let e = end ? dayjs(end) : s;
  if (!e.isValid() || e.isBefore(s, 'day')) e = s;
  const out: string[] = [];
  for (let d = s; !d.isAfter(e, 'day') && out.length < max; d = d.add(1, 'day')) out.push(d.format('YYYY-MM-DD'));
  return out;
}

/** How many days the event spans (1 for a single-day event, 0 when no date is set). */
export function eventDayCount(start?: string | null, end?: string | null) {
  if (!start) return 0;
  const s = dayjs(start);
  if (!s.isValid()) return 0;
  const e = end ? dayjs(end) : s;
  if (!e.isValid() || e.isBefore(s, 'day')) return 1;
  return e.startOf('day').diff(s.startOf('day'), 'day') + 1;
}

export const today = () => dayjs().format('YYYY-MM-DD');
export const nowLocal = () => dayjs().format('YYYY-MM-DDTHH:mm');

/** Converts "2026-11-17 14:30:00" to an <input type="datetime-local"> value. */
export const toLocalInput = (d?: string | null) => (d ? dayjs(d).format('YYYY-MM-DDTHH:mm') : '');

export function phoneDisplay(p?: string | null) {
  if (!p) return '—';
  const m = p.match(/^\+91(\d{5})(\d{5})$/);
  return m ? `+91 ${m[1]} ${m[2]}` : p;
}

export function telLink(p?: string | null) {
  return p ? 'tel:' + p.replace(/[^\d+]/g, '') : undefined;
}

export function waLink(p?: string | null, text = '') {
  if (!p) return undefined;
  let d = p.replace(/\D/g, '');
  if (d.length === 10) d = '91' + d;
  return `https://wa.me/${d}${text ? '?text=' + encodeURIComponent(text) : ''}`;
}

export function initials(name?: string | null) {
  if (!name) return '?';
  const parts = name.replace(/[^A-Za-z\s&]/g, '').split(/\s+/).filter((w) => w && w !== '&');
  return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?';
}

export function pct(n: number) {
  return (Math.round(n * 10) / 10).toString() + '%';
}
