import { useState } from 'react';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, Check, Copy, Minus, Plus, Trash2, X } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { dayjs, fmtDate, fmtTime, inr } from '@/lib/format';
import { Button, Field, Input, Select, Textarea } from '@/components/ui';

/* ---------- Shared types and maths (mirrors Quotations::totals / schedule_amounts on the server) ---------- */

export type Item = { key: string; service_id?: number | null; category?: string | null; name: string; description?: string; qty: string; rate: string; kind: 'package' | 'service' | 'addon'; unit?: string | null };
export type Ev = { key: string; name: string; event_type: string; event_date: string; start_time: string; end_time: string; venue: string; city: string; notes: string; coverage: Record<string, number> };
export type Milestone = { key: string; label: string; percent: string; due: string };

let seq = 0;
export const k = () => 'q' + ++seq;
export const num = (v: string | number | null | undefined) => {
  const n = parseFloat(String(v ?? '').replace(/[₹,\s%]/g, ''));
  return isFinite(n) ? n : 0;
};
export const r2 = (n: number) => Math.round(n * 100) / 100;
const hm = (t?: string | null) => (t ? t.slice(0, 5) : '');

export function quoteTotals(items: { qty: string; rate: string }[], dt: string, dv: number, gst: number) {
  const subtotal = r2(items.reduce((s, i) => s + r2(num(i.qty) * num(i.rate)), 0));
  const discount = dt === 'percent' ? r2((subtotal * Math.min(100, dv)) / 100) : Math.min(subtotal, dv);
  const taxable = subtotal - discount;
  const gstAmt = r2((taxable * gst) / 100);
  return { subtotal, discount: r2(discount), taxable: r2(taxable), gst: gstAmt, total: r2(taxable + gstAmt) };
}

export function scheduleAmounts(ms: Milestone[], total: number) {
  let left = r2(total);
  return ms.map((m, i) => {
    const amt = i === ms.length - 1 ? left : Math.round((total * num(m.percent)) / 100);
    left = r2(left - amt);
    return r2(amt);
  });
}

export const newEvent = (p: Partial<Ev> = {}): Ev => ({ key: k(), name: '', event_type: '', event_date: '', start_time: '', end_time: '', venue: '', city: '', notes: '', coverage: {}, ...p });
export const eventFromApi = (e: any): Ev => newEvent({ name: e.name || '', event_type: e.event_type || '', event_date: e.event_date || '', start_time: hm(e.start_time), end_time: hm(e.end_time), venue: e.venue || '', city: e.city || '', notes: e.notes || '', coverage: { ...(e.coverage || {}) } });
export const milestonesFrom = (list: any[] | undefined | null): Milestone[] => (list || []).map((m) => ({ key: k(), label: m.label || '', percent: String(m.percent ?? ''), due: m.due || '' }));
export const linesFrom = (v: any): string[] => (Array.isArray(v) ? v : String(v || '').split(/\r?\n/)).map((s: string) => s.trim()).filter(Boolean);

export function gstLines(rate: number, amount: number, type: string): [string, number][] {
  if (rate <= 0) return [];
  if (type === 'inter') return [[`IGST ${rate}%`, amount]];
  const half = r2(amount / 2);
  return [[`CGST ${rate / 2}%`, half], [`SGST ${rate / 2}%`, r2(amount - half)]];
}

/* ---------- Stepper ---------- */

export function Stepper({ steps, value, onChange, done }: { steps: [string, string][]; value: string; onChange: (s: string) => void; done: Record<string, boolean> }) {
  const idx = steps.findIndex(([s]) => s === value);
  return (
    <ol className="scrollbar-thin -mx-1 mb-5 flex gap-1 overflow-x-auto px-1" aria-label="Quotation steps">
      {steps.map(([s, label], i) => {
        const active = s === value;
        return (
          <li key={s} className="min-w-0 flex-1">
            <button
              type="button"
              onClick={() => onChange(s)}
              aria-current={active ? 'step' : undefined}
              className={clsx('flex w-full items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left transition-colors', active ? 'border-accent bg-accent-soft' : 'border-line bg-surface hover:bg-subtle')}
            >
              <span className={clsx('flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold', active ? 'bg-accent text-white' : done[s] ? 'bg-emerald-500 text-white' : i < idx ? 'bg-ink/80 text-surface' : 'bg-subtle text-muted')}>
                {done[s] && !active ? <Check className="h-3.5 w-3.5" /> : i + 1}
              </span>
              <span className="min-w-0">
                <span className="block text-2xs uppercase tracking-wide text-faint">Step {i + 1}</span>
                <span className={clsx('block truncate text-sm font-medium', active ? 'text-ink' : 'text-muted')}>{label}</span>
              </span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}

/* ---------- Crew counter per role ---------- */

export function CoverageEditor({ value, onChange, disabled }: { value: Record<string, number>; onChange: (v: Record<string, number>) => void; disabled?: boolean }) {
  const { config } = useAuth();
  const set = (role: string, n: number) => {
    const next = { ...value };
    if (n <= 0) delete next[role];
    else next[role] = Math.min(20, n);
    onChange(next);
  };
  return (
    <div className="flex flex-wrap gap-2">
      {Object.entries(config.quote_coverage_roles || {}).map(([role, label]) => {
        const n = value[role] || 0;
        return (
          <div key={role} className={clsx('inline-flex items-center gap-1 rounded-full border py-0.5 pl-3 pr-1 text-xs', n ? 'border-accent/50 bg-accent-soft text-ink' : 'border-line text-muted')}>
            <span className="mr-1">{label}</span>
            <button type="button" aria-label={`Fewer ${label}`} disabled={disabled || !n} onClick={() => set(role, n - 1)} className="flex h-6 w-6 items-center justify-center rounded-full hover:bg-subtle disabled:opacity-30"><Minus className="h-3 w-3" /></button>
            <span className="tabular w-4 text-center font-semibold">{n}</span>
            <button type="button" aria-label={`More ${label}`} disabled={disabled || n >= 20} onClick={() => set(role, n + 1)} className="flex h-6 w-6 items-center justify-center rounded-full hover:bg-subtle disabled:opacity-30"><Plus className="h-3 w-3" /></button>
          </div>
        );
      })}
    </div>
  );
}

/* ---------- Event days ---------- */

export function EventsEditor({ value, onChange, disabled, error }: { value: Ev[]; onChange: (v: Ev[]) => void; disabled?: boolean; error?: string }) {
  const { config } = useAuth();
  const set = (key: string, patch: Partial<Ev>) => onChange(value.map((e) => (e.key === key ? { ...e, ...patch } : e)));
  const move = (i: number, d: number) => {
    const n = [...value];
    const [x] = n.splice(i, 1);
    n.splice(i + d, 0, x);
    onChange(n);
  };
  const copyNext = (e: Ev) => {
    const date = e.event_date ? dayjs(e.event_date).add(1, 'day').format('YYYY-MM-DD') : '';
    onChange([...value, newEvent({ ...e, key: undefined as any, name: '', event_date: date, coverage: { ...e.coverage } })]);
  };
  return (
    <div className="space-y-3">
      {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{error}</p>}
      {value.length === 0 && <p className="rounded-xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">No events yet. Add each function (haldi, wedding, reception…) as its own day, with its date, venue and team.</p>}
      {value.map((e, i) => (
        <div key={e.key} className="rounded-xl border border-line bg-surface p-3 sm:p-4">
          <div className="mb-3 flex items-center gap-2">
            <span className="rounded-full bg-accent-soft px-2.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-accent-ink">Day {i + 1}</span>
            {e.event_date && <span className="text-xs text-muted">{dayjs(e.event_date).format('ddd')}, {fmtDate(e.event_date)}</span>}
            {!disabled && (
              <div className="ml-auto flex items-center gap-0.5 text-faint">
                <button type="button" className="rounded p-1.5 hover:bg-subtle hover:text-ink disabled:opacity-30" onClick={() => move(i, -1)} disabled={i === 0} aria-label="Move up"><ArrowUp className="h-3.5 w-3.5" /></button>
                <button type="button" className="rounded p-1.5 hover:bg-subtle hover:text-ink disabled:opacity-30" onClick={() => move(i, 1)} disabled={i === value.length - 1} aria-label="Move down"><ArrowDown className="h-3.5 w-3.5" /></button>
                <button type="button" className="rounded p-1.5 hover:bg-subtle hover:text-ink" onClick={() => copyNext(e)} title="Copy to the next day" aria-label="Copy to the next day"><Copy className="h-3.5 w-3.5" /></button>
                <button type="button" className="rounded p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" onClick={() => onChange(value.filter((x) => x.key !== e.key))} aria-label="Remove event"><Trash2 className="h-3.5 w-3.5" /></button>
              </div>
            )}
          </div>
          <div className="grid gap-3 sm:grid-cols-6">
            <Field label="Event name" required className="sm:col-span-3">
              <Input value={e.name} onChange={(x) => set(e.key, { name: x.target.value })} placeholder="e.g. Haldi, Wedding, Reception" disabled={disabled} maxLength={190} />
            </Field>
            <Field label="Type" className="sm:col-span-3">
              <Select value={e.event_type} onChange={(x) => set(e.key, { event_type: x.target.value })} options={config.event_types} placeholder="Select…" disabled={disabled} />
            </Field>
            <Field label="Date" className="sm:col-span-2">
              <Input type="date" value={e.event_date} onChange={(x) => set(e.key, { event_date: x.target.value })} disabled={disabled} />
            </Field>
            <Field label="From" className="sm:col-span-2">
              <Input type="time" value={e.start_time} onChange={(x) => set(e.key, { start_time: x.target.value })} disabled={disabled} />
            </Field>
            <Field label="To" className="sm:col-span-2">
              <Input type="time" value={e.end_time} onChange={(x) => set(e.key, { end_time: x.target.value })} disabled={disabled} />
            </Field>
            <Field label="Venue" className="sm:col-span-4">
              <Input value={e.venue} onChange={(x) => set(e.key, { venue: x.target.value })} placeholder="Hotel / lawn / home" disabled={disabled} maxLength={255} />
            </Field>
            <Field label="City" className="sm:col-span-2">
              <Input value={e.city} onChange={(x) => set(e.key, { city: x.target.value })} disabled={disabled} maxLength={100} />
            </Field>
            <div className="sm:col-span-6">
              <p className="label mb-1.5">Team for this day</p>
              <CoverageEditor value={e.coverage} onChange={(c) => set(e.key, { coverage: c })} disabled={disabled} />
            </div>
            <Field label="Notes for the client" className="sm:col-span-6">
              <Input value={e.notes} onChange={(x) => set(e.key, { notes: x.target.value })} placeholder="Optional, e.g. getting-ready shoot at the bride's home" disabled={disabled} />
            </Field>
          </div>
        </div>
      ))}
      {!disabled && (
        <Button icon={<Plus className="h-4 w-4" />} onClick={() => onChange([...value, newEvent(value.length ? { venue: value[value.length - 1].venue, city: value[value.length - 1].city, event_type: value[value.length - 1].event_type, coverage: { ...value[value.length - 1].coverage }, event_date: value[value.length - 1].event_date ? dayjs(value[value.length - 1].event_date).add(1, 'day').format('YYYY-MM-DD') : '' } : {})])}>
          {value.length ? 'Add another day' : 'Add event day'}
        </Button>
      )}
    </div>
  );
}

/* ---------- Bulleted lines (deliverables / not included) with preset chips ---------- */

export function LinesEditor({ value, onChange, presets, placeholder, disabled }: { value: string[]; onChange: (v: string[]) => void; presets?: string[]; placeholder: string; disabled?: boolean }) {
  const [draft, setDraft] = useState('');
  const has = (s: string) => value.some((v) => v.toLowerCase() === s.toLowerCase());
  const add = (s: string) => {
    const t = s.trim();
    if (!t || has(t)) return;
    onChange([...value, t.slice(0, 255)]);
  };
  return (
    <div className="space-y-3">
      {presets && presets.length > 0 && !disabled && (
        <div className="flex flex-wrap gap-1.5">
          {presets.map((p) => {
            const on = has(p);
            return (
              <button key={p} type="button" onClick={() => (on ? onChange(value.filter((v) => v.toLowerCase() !== p.toLowerCase())) : add(p))} className={clsx('inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs transition-colors', on ? 'border-accent bg-accent-soft text-ink' : 'border-line text-muted hover:bg-subtle hover:text-ink')}>
                {on ? <Check className="h-3 w-3" /> : <Plus className="h-3 w-3" />}
                {p}
              </button>
            );
          })}
        </div>
      )}
      {value.length > 0 && (
        <ul className="divide-y divide-line rounded-xl border border-line">
          {value.map((v, i) => (
            <li key={v + i} className="flex items-center gap-2 px-3 py-2 text-sm">
              <Check className="h-3.5 w-3.5 shrink-0 text-accent-ink" />
              <Input value={v} onChange={(e) => onChange(value.map((x, j) => (j === i ? e.target.value : x)))} className="border-transparent bg-transparent px-1 py-0.5 shadow-none" disabled={disabled} maxLength={255} aria-label={`Line ${i + 1}`} />
              {!disabled && (
                <>
                  <button type="button" className="rounded p-1 text-faint hover:bg-subtle hover:text-ink disabled:opacity-30" disabled={i === 0} onClick={() => { const n = [...value]; [n[i - 1], n[i]] = [n[i], n[i - 1]]; onChange(n); }} aria-label="Move up"><ArrowUp className="h-3.5 w-3.5" /></button>
                  <button type="button" className="rounded p-1 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label="Remove"><X className="h-3.5 w-3.5" /></button>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
      {!disabled && (
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); add(draft); setDraft(''); }}>
          <Input value={draft} onChange={(e) => setDraft(e.target.value)} placeholder={placeholder} maxLength={255} />
          <Button type="submit" icon={<Plus className="h-4 w-4" />} disabled={!draft.trim()}>Add</Button>
        </form>
      )}
    </div>
  );
}

/* ---------- Payment milestones ---------- */

export function ScheduleEditor({ value, onChange, total, disabled, error }: { value: Milestone[]; onChange: (v: Milestone[]) => void; total: number; disabled?: boolean; error?: string }) {
  const sum = r2(value.reduce((s, m) => s + num(m.percent), 0));
  const amounts = scheduleAmounts(value, total);
  const set = (key: string, patch: Partial<Milestone>) => onChange(value.map((m) => (m.key === key ? { ...m, ...patch } : m)));
  const preset = (p: number[]) => {
    const labels = p.length === 1 ? ['Full payment'] : p.length === 2 ? ['Advance to confirm the booking', 'Before final delivery'] : ['Advance to confirm the booking', 'Before the event', 'On delivery'];
    const due = p.length === 1 ? ['On booking'] : p.length === 2 ? ['On booking', 'Before final delivery'] : ['On booking', '7 days before the first event', 'Before final delivery'];
    onChange(p.map((x, i) => ({ key: k(), label: labels[i], percent: String(x), due: due[i] })));
  };
  return (
    <div className="space-y-3">
      {!disabled && (
        <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
          Quick split:
          {[[50, 30, 20], [50, 50], [30, 40, 30], [25, 50, 25], [100]].map((p) => (
            <button key={p.join()} type="button" onClick={() => preset(p)} className="rounded-md border border-line px-2 py-0.5 hover:bg-subtle hover:text-ink">{p.join(' / ')}</button>
          ))}
        </div>
      )}
      <div className="overflow-hidden rounded-xl border border-line">
        {value.length === 0 && <p className="px-4 py-5 text-center text-sm text-muted">No payment plan. The quotation will show only the total.</p>}
        {value.map((m, i) => (
          <div key={m.key} className="grid grid-cols-12 items-center gap-2 border-b border-line px-3 py-2 last:border-b-0">
            <span className="col-span-1 hidden text-xs font-semibold text-faint sm:block">{i + 1}</span>
            <Input className="col-span-12 sm:col-span-4" value={m.label} onChange={(e) => set(m.key, { label: e.target.value })} placeholder="Milestone, e.g. Advance" disabled={disabled} maxLength={120} aria-label={`Milestone ${i + 1} name`} />
            <Input className="col-span-7 sm:col-span-3" value={m.due} onChange={(e) => set(m.key, { due: e.target.value })} placeholder="When, e.g. On booking" disabled={disabled} maxLength={120} aria-label={`Milestone ${i + 1} due`} />
            <div className="relative col-span-3 sm:col-span-2">
              <Input value={m.percent} onChange={(e) => set(m.key, { percent: e.target.value })} inputMode="decimal" className="pr-6 text-right" disabled={disabled} aria-label={`Milestone ${i + 1} percent`} />
              <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-faint">%</span>
            </div>
            <div className="col-span-2 flex items-center justify-end gap-1 sm:col-span-2">
              <span className="tabular hidden text-sm font-medium sm:inline">{inr(amounts[i])}</span>
              {!disabled && <button type="button" className="rounded p-1 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" onClick={() => onChange(value.filter((x) => x.key !== m.key))} aria-label="Remove milestone"><X className="h-3.5 w-3.5" /></button>}
            </div>
            <span className="tabular col-span-12 -mt-1 text-right text-xs text-muted sm:hidden">{inr(amounts[i])}</span>
          </div>
        ))}
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        {!disabled && value.length < 6 && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => onChange([...value, { key: k(), label: '', percent: String(Math.max(0, r2(100 - sum))), due: '' }])}>Add milestone</Button>}
        {value.length > 0 && <span className={clsx('ml-auto text-xs font-medium', Math.abs(sum - 100) < 0.01 ? 'text-emerald-600' : 'text-red-600')}>{sum}% of 100%{Math.abs(sum - 100) >= 0.01 ? ' · must add up to 100%' : ''}</span>}
      </div>
      {error && <p className="text-xs text-red-600">{error}</p>}
    </div>
  );
}

/* ---------- Live client preview (same sections as the public page and PDF) ---------- */

export type PreviewData = {
  number?: string;
  title: string;
  customer_name: string;
  event_type: string;
  valid_until: string;
  intro: string;
  closing: string;
  notes: string;
  terms: string;
  events: Ev[];
  items: Item[];
  deliverables: string[];
  exclusions: string[];
  schedule: Milestone[];
  totals: ReturnType<typeof quoteTotals>;
  discount_type: string;
  discount_value: number;
  gst_rate: number;
  gst_type: string;
  show_prices: boolean;
};

export function QuotePreview({ d }: { d: PreviewData }) {
  const { config, studio } = useAuth();
  const accent = /^#[0-9a-f]{6}$/i.test(studio.settings?.quote_accent || '') ? studio.settings.quote_accent : '#F3724C';
  const kinds: [string, string][] = [['package', 'Packages'], ['service', 'Services'], ['addon', 'Add-ons']];
  const groups = kinds.map(([kd, label]) => ({ kd, label, items: d.items.filter((i) => (i.kind || 'service') === kd) })).filter((g) => g.items.length);
  const amounts = scheduleAmounts(d.schedule, d.totals.total);
  const cov = (c: Record<string, number>) => Object.entries(c).filter(([, n]) => n > 0).map(([r, n]) => (config.quote_coverage_roles?.[r] || r) + (n > 1 ? ` × ${n}` : ''));
  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-surface text-sm shadow-sm">
      <div className="px-5 py-5 text-white sm:px-7" style={{ background: accent }}>
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {studio.logo_url && <img src={studio.logo_url} alt="" className="h-10 max-w-[120px] rounded bg-white object-contain p-1" />}
            <div className="min-w-0">
              <p className="truncate font-semibold">{studio.name}</p>
              <p className="truncate text-xs opacity-80">{[studio.city, studio.phone].filter(Boolean).join(' · ')}</p>
            </div>
          </div>
          <div className="shrink-0 text-right">
            <p className="text-lg font-bold tracking-[0.2em]">QUOTATION</p>
            <p className="text-xs opacity-90">{d.number || 'Number on save'} · Valid until {fmtDate(d.valid_until)}</p>
          </div>
        </div>
      </div>
      <div className="space-y-5 px-5 py-5 sm:px-7">
        <div>
          <p className="text-2xs uppercase tracking-wide text-faint">Prepared for</p>
          <p className="text-base font-semibold">{d.customer_name || 'Client name'}</p>
          <p className="text-muted">{d.title || 'Quotation title'}{d.event_type ? ` · ${config.event_types[d.event_type]}` : ''}</p>
        </div>
        {d.intro && <p className="whitespace-pre-line text-muted">{d.intro}</p>}
        {d.events.length > 0 && (
          <PreviewSection title="Event schedule & coverage">
            <div className="grid gap-2 sm:grid-cols-2">
              {d.events.map((e, i) => (
                <div key={e.key} className="rounded-xl border border-line p-3">
                  <p className="text-2xs font-semibold uppercase tracking-wide text-accent-ink">Day {i + 1}</p>
                  <p className="font-medium">{e.name || 'Event'}</p>
                  <p className="text-xs text-muted">{[e.event_date && `${dayjs(e.event_date).format('ddd')}, ${fmtDate(e.event_date)}`, e.start_time && fmtTime(e.start_time) + (e.end_time ? ' – ' + fmtTime(e.end_time) : '')].filter(Boolean).join(' · ')}</p>
                  {(e.venue || e.city) && <p className="text-xs">{[e.venue, e.city].filter(Boolean).join(', ')}</p>}
                  {cov(e.coverage).length > 0 && <div className="mt-2 flex flex-wrap gap-1">{cov(e.coverage).map((c) => <span key={c} className="rounded-full border border-line px-2 py-0.5 text-2xs">{c}</span>)}</div>}
                </div>
              ))}
            </div>
          </PreviewSection>
        )}
        <PreviewSection title="Investment">
          <div className="overflow-hidden rounded-xl border border-line">
            {groups.length === 0 && <p className="px-3 py-4 text-center text-muted">No services added yet.</p>}
            {groups.map((g) => (
              <div key={g.kd} className="border-b border-line last:border-b-0">
                {groups.length > 1 && <p className="bg-subtle/60 px-3 py-1.5 text-2xs font-semibold uppercase tracking-wide text-accent-ink">{g.label}</p>}
                {g.items.map((it) => (
                  <div key={it.key} className="flex items-baseline gap-3 px-3 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="font-medium">{it.name || 'Untitled item'}</p>
                      {it.description && <p className="text-xs text-muted">{it.description}</p>}
                    </div>
                    <span className="tabular shrink-0 text-xs text-muted">{num(it.qty)}{it.unit ? ' ' + it.unit : ''}{d.show_prices ? ` × ${inr(num(it.rate))}` : ''}</span>
                    {d.show_prices && <span className="tabular w-24 shrink-0 text-right font-medium">{inr(r2(num(it.qty) * num(it.rate)))}</span>}
                  </div>
                ))}
              </div>
            ))}
          </div>
          <div className="ml-auto mt-3 max-w-xs space-y-1">
            <Line label="Subtotal" value={inr(d.totals.subtotal)} />
            {d.totals.discount > 0 && <Line label={`Discount${d.discount_type === 'percent' ? ` (${d.discount_value}%)` : ''}`} value={'− ' + inr(d.totals.discount)} />}
            {gstLines(d.gst_rate, d.totals.gst, d.gst_type).map(([l, v]) => <Line key={l} label={l} value={inr(v)} muted />)}
            <div className="flex justify-between border-t-2 pt-2 text-base font-semibold" style={{ borderColor: accent }}><span>Total</span><span className="tabular">{inr(d.totals.total)}</span></div>
          </div>
        </PreviewSection>
        {d.schedule.length > 0 && (
          <PreviewSection title="Payment plan">
            <div className="divide-y divide-line rounded-xl border border-line">
              {d.schedule.map((m, i) => (
                <div key={m.key} className="flex items-center gap-3 px-3 py-2">
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-2xs font-bold text-accent-ink">{i + 1}</span>
                  <div className="min-w-0 flex-1"><p className="font-medium">{m.label || 'Milestone'}</p>{m.due && <p className="text-xs text-muted">{m.due}</p>}</div>
                  <span className="text-xs text-muted">{num(m.percent)}%</span>
                  <span className="tabular w-24 text-right font-medium">{inr(amounts[i])}</span>
                </div>
              ))}
            </div>
          </PreviewSection>
        )}
        {(d.deliverables.length > 0 || d.exclusions.length > 0) && (
          <div className="grid gap-4 sm:grid-cols-2">
            {d.deliverables.length > 0 && (
              <PreviewSection title="What you will receive">
                <ul className="space-y-1">{d.deliverables.map((x) => <li key={x} className="flex gap-2"><Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-accent-ink" />{x}</li>)}</ul>
              </PreviewSection>
            )}
            {d.exclusions.length > 0 && (
              <PreviewSection title="Not included">
                <ul className="space-y-1 text-muted">{d.exclusions.map((x) => <li key={x} className="flex gap-2"><Minus className="mt-0.5 h-3.5 w-3.5 shrink-0" />{x}</li>)}</ul>
              </PreviewSection>
            )}
          </div>
        )}
        {d.notes && <PreviewSection title="Notes"><p className="whitespace-pre-line">{d.notes}</p></PreviewSection>}
        {d.terms && <PreviewSection title="Terms & conditions"><p className="whitespace-pre-line text-muted">{d.terms}</p></PreviewSection>}
        {d.closing && <p className="whitespace-pre-line border-t border-line pt-4 text-center font-serif text-base italic">{d.closing}</p>}
      </div>
    </div>
  );
}

function PreviewSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="mb-2 text-2xs font-semibold uppercase tracking-wide text-faint">{title}</p>
      {children}
    </div>
  );
}

function Line({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
  return (
    <div className={clsx('flex justify-between', muted && 'text-xs text-muted')}>
      <span className={muted ? '' : 'text-muted'}>{label}</span>
      <span className="tabular">{value}</span>
    </div>
  );
}

export function TextBlock({ label, hint, value, onChange, rows = 3, disabled }: { label: string; hint?: string; value: string; onChange: (v: string) => void; rows?: number; disabled?: boolean }) {
  return (
    <Field label={label} hint={hint}>
      <Textarea rows={rows} value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled} />
    </Field>
  );
}
