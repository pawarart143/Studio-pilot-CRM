import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowLeft, CalendarCheck, Copy, Download, ExternalLink, FileText, Link2, MoreHorizontal, Plus, Search, Trash2 } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, fmtDateTime, inr, today, dayjs } from '@/lib/format';
import { quoteTone } from '@/lib/tones';
import { Badge, Button, Card, CardHeader, ErrorState, Field, Input, Menu, MenuItem, PageHeader, PageLoader, Select, Textarea, useUi, Segmented } from '@/components/ui';
import { WhatsAppModal } from '@/components/shared';

type Item = { key: string; service_id?: number | null; category?: string | null; name: string; description?: string; qty: string; rate: string };
type Head = {
  title: string;
  customer_name: string;
  customer_phone: string;
  customer_email: string;
  event_type: string;
  event_date: string;
  venue: string;
  valid_until: string;
  discount_type: 'flat' | 'percent';
  discount_value: string;
  gst_rate: string;
  notes: string;
  terms: string;
  lead_id: number | null;
  client_id: number | null;
};

let seq = 0;
const k = () => 'i' + ++seq;
const num = (v: string | number | null | undefined) => {
  const n = parseFloat(String(v ?? '').replace(/[₹,\s]/g, ''));
  return isFinite(n) ? n : 0;
};
const r2 = (n: number) => Math.round(n * 100) / 100;

/** Mirrors Quotations::totals() on the server so the preview matches what is saved. */
export function quoteTotals(items: Item[], dt: string, dv: number, gst: number) {
  const subtotal = r2(items.reduce((s, i) => s + r2(num(i.qty) * num(i.rate)), 0));
  const discount = dt === 'percent' ? r2((subtotal * Math.min(100, dv)) / 100) : Math.min(subtotal, dv);
  const taxable = subtotal - discount;
  const gstAmt = r2((taxable * gst) / 100);
  return { subtotal, discount: r2(discount), taxable: r2(taxable), gst: gstAmt, total: r2(taxable + gstAmt) };
}

export default function QuotationBuilder() {
  const { id } = useParams();
  const isNew = !id;
  const [sp] = useSearchParams();
  const leadId = sp.get('lead_id');
  const clientId = sp.get('client_id');
  const nav = useNavigate();
  const qc = useQueryClient();
  const { config, studio, can } = useAuth();
  const { toast, confirm } = useUi();
  const editable = can('quotes.edit');

  const existing = useQuery({ queryKey: ['quotation', id], queryFn: () => api.get(`quotations/${id}`), enabled: !isNew });
  const lead = useQuery({ queryKey: ['lead', leadId], queryFn: () => api.get(`leads/${leadId}`), enabled: isNew && !!leadId });
  const client = useQuery({ queryKey: ['client', clientId], queryFn: () => api.get(`clients/${clientId}`), enabled: isNew && !!clientId && !leadId });
  const services = useQuery({ queryKey: ['services', 'active'], queryFn: () => api.get<any[]>('services', { active: 1 }) });

  const settings = studio.settings || {};
  const blank = (): Head => ({
    title: '',
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    event_type: '',
    event_date: '',
    venue: '',
    valid_until: dayjs().add(Number(settings.quote_validity || 15), 'day').format('YYYY-MM-DD'),
    discount_type: 'flat',
    discount_value: '0',
    gst_rate: settings.gst_enabled === false ? '0' : String(settings.gst_rate ?? 18),
    notes: '',
    terms: settings.quote_terms || '',
    lead_id: null,
    client_id: null,
  });
  const [h, setH] = useState<Head>(blank);
  const [items, setItems] = useState<Item[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [picker, setPicker] = useState('');
  const [wa, setWa] = useState(false);
  const [ready, setReady] = useState(isNew && !leadId && !clientId);

  // Prefill from an existing quotation, a lead or a client.
  useEffect(() => {
    const q = existing.data;
    if (!q) return;
    setH({
      title: q.title || '',
      customer_name: q.customer_name || '',
      customer_phone: q.customer_phone || '',
      customer_email: q.customer_email || '',
      event_type: q.event_type || '',
      event_date: q.event_date || '',
      venue: q.venue || '',
      valid_until: q.valid_until || '',
      discount_type: q.discount_type || 'flat',
      discount_value: String(q.discount_value ?? 0),
      gst_rate: String(q.gst_rate ?? 0),
      notes: q.notes || '',
      terms: q.terms || '',
      lead_id: q.lead_id,
      client_id: q.client_id,
    });
    setItems(q.items.map((i: any) => ({ key: k(), service_id: i.service_id, category: i.category, name: i.name, description: i.description || '', qty: String(i.qty), rate: String(i.rate) })));
    setDirty(false);
    setReady(true);
  }, [existing.data]);

  useEffect(() => {
    const l = lead.data;
    if (!l) return;
    const et = l.event_type ? config.event_types[l.event_type] : '';
    setH((p) => ({ ...p, lead_id: l.id, client_id: l.client_id || null, title: `${et || 'Photography'} coverage – ${l.name}`, customer_name: l.name, customer_phone: l.phone || '', customer_email: l.email || '', event_type: l.event_type || '', event_date: l.event_date || '', venue: [l.venue, l.city].filter(Boolean).join(', ') }));
    setReady(true);
  }, [lead.data, config.event_types]);

  useEffect(() => {
    const c = client.data;
    if (!c) return;
    setH((p) => ({ ...p, client_id: c.id, title: `Photography coverage – ${c.name}`, customer_name: c.name, customer_phone: c.phone || '', customer_email: c.email || '' }));
    setReady(true);
  }, [client.data]);

  const set = <K extends keyof Head>(key: K, v: Head[K]) => {
    setH((p) => ({ ...p, [key]: v }));
    setDirty(true);
    if (errors[key as string]) setErrors((e) => ({ ...e, [key]: '' }));
  };
  const setItem = (key: string, patch: Partial<Item>) => {
    setItems((list) => list.map((i) => (i.key === key ? { ...i, ...patch } : i)));
    setDirty(true);
  };
  const addService = (s: any) => {
    setItems((list) => [...list, { key: k(), service_id: s.id, category: s.category, name: s.name, description: s.description || '', qty: '1', rate: String(s.price) }]);
    setDirty(true);
  };
  const addCustom = () => {
    setItems((list) => [...list, { key: k(), service_id: null, category: null, name: '', description: '', qty: '1', rate: '' }]);
    setDirty(true);
  };
  const move = (idx: number, d: number) => {
    setItems((list) => {
      const n = [...list];
      const j = idx + d;
      if (j < 0 || j >= n.length) return list;
      [n[idx], n[j]] = [n[j], n[idx]];
      return n;
    });
    setDirty(true);
  };

  const t = useMemo(() => quoteTotals(items, h.discount_type, num(h.discount_value), num(h.gst_rate)), [items, h.discount_type, h.discount_value, h.gst_rate]);

  const grouped = useMemo(() => {
    const g: Record<string, any[]> = {};
    const s = picker.trim().toLowerCase();
    (services.data || []).filter((x) => !s || x.name.toLowerCase().includes(s)).forEach((x) => (g[x.category] ||= []).push(x));
    return g;
  }, [services.data, picker]);

  const save = async (): Promise<number | null> => {
    const e: Record<string, string> = {};
    if (!h.title.trim()) e.title = 'Give the quotation a title.';
    if (!h.customer_name.trim()) e.customer_name = 'Customer name is required.';
    if (h.customer_phone && h.customer_phone.replace(/\D/g, '').length < 10) e.customer_phone = 'Enter a valid phone number.';
    if (h.customer_email && !/^\S+@\S+\.\S+$/.test(h.customer_email)) e.customer_email = 'Enter a valid email.';
    if (num(h.gst_rate) < 0 || num(h.gst_rate) > 28) e.gst_rate = 'GST must be between 0 and 28%.';
    if (num(h.discount_value) < 0) e.discount_value = 'Discount cannot be negative.';
    if (!items.length) e.items = 'Add at least one service.';
    items.forEach((i, n) => {
      if (!i.name.trim()) e.items = `Item ${n + 1} needs a name.`;
      else if (num(i.qty) <= 0) e.items = `Item ${n + 1}: quantity must be more than 0.`;
      else if (i.rate === '' || num(i.rate) < 0) e.items = `Item ${n + 1}: enter a rate.`;
    });
    setErrors(e);
    if (Object.values(e).some(Boolean)) {
      toast('Please fix the highlighted fields.', 'error');
      return null;
    }
    const body = {
      ...h,
      discount_value: num(h.discount_value),
      gst_rate: num(h.gst_rate),
      items: items.map(({ key, ...i }) => ({ ...i, qty: num(i.qty), rate: num(i.rate) })),
    };
    setSaving(true);
    try {
      const r = isNew ? await api.post('quotations', body) : await api.put(`quotations/${id}`, body);
      ['quotations', 'lead', 'dashboard'].forEach((x) => qc.invalidateQueries({ queryKey: [x] }));
      setDirty(false);
      if (isNew) {
        toast(`Quotation ${r.number} created`);
        nav(`/quotations/${r.id}`, { replace: true });
      } else {
        await qc.invalidateQueries({ queryKey: ['quotation', id] });
        toast('Quotation saved');
      }
      return r.id;
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      toast((err as Error).message, 'error');
      return null;
    } finally {
      setSaving(false);
    }
  };

  const q = existing.data;
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['quotation', id] });
    qc.invalidateQueries({ queryKey: ['quotations'] });
    qc.invalidateQueries({ queryKey: ['lead'] });
  };
  const setStatus = async (status: string) => {
    try {
      await api.post(`quotations/${id}/status`, { status });
      toast(`Marked as ${config.quotation_statuses[status]}`);
      refresh();
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const ensureSaved = async () => (dirty ? await save() : Number(id));
  const pdf = async (download = false) => {
    if (!(await ensureSaved())) return;
    try {
      await api.openPdf(`quotations/${id}/pdf`, `${q.number}.pdf`, download);
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(q.public_url);
      toast('Client link copied. Anyone with the link can view and accept this quotation.');
    } catch {
      window.prompt('Copy this link', q.public_url);
    }
  };
  const duplicate = async () => {
    try {
      const r = await api.post(`quotations/${id}/duplicate`, {});
      qc.invalidateQueries({ queryKey: ['quotations'] });
      toast(`Copied as ${r.number}`);
      nav(`/quotations/${r.id}`);
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const remove = async () => {
    if (!(await confirm({ title: `Delete ${q.number}?`, message: 'The quotation and its client link will stop working. This cannot be undone.', danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`quotations/${id}`);
      qc.invalidateQueries({ queryKey: ['quotations'] });
      toast('Quotation deleted');
      nav('/quotations');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const convert = async () => {
    if (!(await ensureSaved())) return;
    nav(q.lead_id ? `/bookings/new?lead_id=${q.lead_id}&quotation_id=${q.id}` : `/bookings/new?quotation_id=${q.id}`);
  };

  if (existing.error) return <ErrorState error={existing.error} onRetry={() => existing.refetch()} />;
  if (lead.error) return <ErrorState error={lead.error} />;
  // The same component instance is reused when /quotations/new navigates to /quotations/:id.
  if (!ready || (!isNew && !existing.data)) return <PageLoader />;

  const half = r2(t.gst / 2);
  const ro = !editable;

  return (
    <div className="pb-24 lg:pb-0">
      <PageHeader
        back={<Link to="/quotations" className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Quotations</Link>}
        title={
          <span className="flex items-center gap-2">
            {isNew ? 'New quotation' : q.number}
            {q && <Badge tone={quoteTone[q.status]}>{config.quotation_statuses[q.status]}</Badge>}
          </span>
        }
        subtitle={
          q ? (
            <>
              Created {fmtDate(q.created_at)}
              {q.sent_at && ` · Sent ${fmtDateTime(q.sent_at)}`}
              {q.viewed_at && ` · Viewed ${fmtDateTime(q.viewed_at)}`}
              {q.responded_at && ` · Responded ${fmtDateTime(q.responded_at)}`}
              {q.lead_id ? <> · <Link to={`/leads/${q.lead_id}`} className="text-accent-ink">Open lead</Link></> : null}
            </>
          ) : h.lead_id ? (
            <>For lead <Link to={`/leads/${h.lead_id}`} className="text-accent-ink">{h.customer_name}</Link></>
          ) : (
            'Pick services from your catalogue or add custom items.'
          )
        }
        actions={
          q && (
            <>
              <Button icon={<FileText className="h-4 w-4" />} onClick={() => pdf(false)}>View PDF</Button>
              <Button variant="success" onClick={() => setWa(true)} disabled={dirty} title={dirty ? 'Save first' : undefined}>Share on WhatsApp</Button>
              {q.booking_id ? (
                <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} onClick={() => nav(`/bookings/${q.booking_id}`)}>Open booking</Button>
              ) : (
                can('bookings.edit') && q.status !== 'rejected' && <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} onClick={convert}>Convert to booking</Button>
              )}
              <Menu trigger={() => <Button icon={<MoreHorizontal className="h-4 w-4" />} aria-label="More actions" />}>
                {(close) => (
                  <>
                    <MenuItem icon={<Download className="h-4 w-4" />} onClick={() => (close(), pdf(true))}>Download PDF</MenuItem>
                    <MenuItem icon={<Link2 className="h-4 w-4" />} onClick={() => (close(), copyLink())}>Copy client link</MenuItem>
                    <MenuItem icon={<ExternalLink className="h-4 w-4" />} onClick={() => (close(), window.open(q.public_url, '_blank'))}>Preview client page</MenuItem>
                    {editable && <MenuItem icon={<Copy className="h-4 w-4" />} onClick={() => (close(), duplicate())}>Duplicate</MenuItem>}
                    {editable && (
                      <>
                        <div className="my-1 border-t border-line" />
                        <p className="px-3 pb-1 pt-1.5 text-2xs font-medium uppercase tracking-wide text-faint">Set status</p>
                        {Object.entries(config.quotation_statuses)
                          .filter(([s]) => s !== q.status)
                          .map(([s, label]) => (
                            <MenuItem key={s} onClick={() => (close(), setStatus(s))}>
                              <Badge tone={quoteTone[s]}>{label}</Badge>
                            </MenuItem>
                          ))}
                      </>
                    )}
                    {can('quotes.delete') && (
                      <>
                        <div className="my-1 border-t border-line" />
                        <MenuItem danger icon={<Trash2 className="h-4 w-4" />} onClick={() => (close(), remove())}>Delete</MenuItem>
                      </>
                    )}
                  </>
                )}
              </Menu>
            </>
          )
        }
      />

      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <div className="min-w-0 space-y-5">
          <Card className="p-4 sm:p-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Quotation title" required error={errors.title} className="sm:col-span-2">
                <Input value={h.title} onChange={(e) => set('title', e.target.value)} placeholder="e.g. Wedding photography & films – Sharma family" invalid={!!errors.title} disabled={ro} />
              </Field>
              <Field label="Customer name" required error={errors.customer_name}>
                <Input value={h.customer_name} onChange={(e) => set('customer_name', e.target.value)} invalid={!!errors.customer_name} disabled={ro} />
              </Field>
              <Field label="Phone" error={errors.customer_phone}>
                <Input value={h.customer_phone} onChange={(e) => set('customer_phone', e.target.value)} inputMode="tel" placeholder="98765 43210" invalid={!!errors.customer_phone} disabled={ro} />
              </Field>
              <Field label="Email" error={errors.customer_email}>
                <Input type="email" value={h.customer_email} onChange={(e) => set('customer_email', e.target.value)} invalid={!!errors.customer_email} disabled={ro} />
              </Field>
              <Field label="Event type">
                <Select value={h.event_type} onChange={(e) => set('event_type', e.target.value)} options={config.event_types} placeholder="Select…" disabled={ro} />
              </Field>
              <Field label="Event date" error={errors.event_date}>
                <Input type="date" value={h.event_date} onChange={(e) => set('event_date', e.target.value)} disabled={ro} />
              </Field>
              <Field label="Valid until" error={errors.valid_until}>
                <Input type="date" value={h.valid_until} min={isNew ? today() : undefined} onChange={(e) => set('valid_until', e.target.value)} disabled={ro} />
              </Field>
              <Field label="Venue / city" className="sm:col-span-2">
                <Input value={h.venue} onChange={(e) => set('venue', e.target.value)} placeholder="Taj Lands End, Mumbai" disabled={ro} />
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Services" subtitle="Rates are pre-filled from your catalogue. Change any line for this client." actions={editable && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={addCustom}>Custom item</Button>} />
            {errors.items && <p className="mx-4 mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{errors.items}</p>}
            {items.length === 0 ? (
              <p className="px-4 pb-6 pt-2 text-center text-sm text-muted">No items yet. Pick a service from the catalogue{editable ? ' on the right' : ''}.</p>
            ) : (
              <ul className="divide-y divide-line border-t border-line">
                {items.map((it, idx) => (
                  <li key={it.key} className="grid grid-cols-12 gap-2 px-4 py-3">
                    <div className="col-span-12 space-y-1.5 sm:col-span-6">
                      <Input value={it.name} onChange={(e) => setItem(it.key, { name: e.target.value })} placeholder="Item name" className="font-medium" disabled={ro} />
                      <Textarea value={it.description} onChange={(e) => setItem(it.key, { description: e.target.value })} rows={1} placeholder="Details (optional)" className="text-xs" disabled={ro} />
                    </div>
                    <div className="col-span-3 sm:col-span-2">
                      <label className="label sm:hidden">Qty</label>
                      <Input value={it.qty} onChange={(e) => setItem(it.key, { qty: e.target.value })} inputMode="decimal" className="text-right" aria-label="Quantity" disabled={ro} />
                    </div>
                    <div className="col-span-5 sm:col-span-2">
                      <label className="label sm:hidden">Rate</label>
                      <Input value={it.rate} onChange={(e) => setItem(it.key, { rate: e.target.value })} inputMode="decimal" className="text-right" aria-label="Rate" placeholder="0" disabled={ro} />
                    </div>
                    <div className="col-span-4 flex items-start justify-end gap-1 sm:col-span-2">
                      <span className="tabular pt-2 text-sm font-medium">{inr(r2(num(it.qty) * num(it.rate)))}</span>
                    </div>
                    {editable && (
                      <div className="col-span-12 -mt-1 flex justify-end gap-1 text-xs text-faint">
                        <button className="rounded px-1.5 py-0.5 hover:bg-subtle hover:text-ink disabled:opacity-30" onClick={() => move(idx, -1)} disabled={idx === 0}>Move up</button>
                        <button className="rounded px-1.5 py-0.5 hover:bg-subtle hover:text-ink disabled:opacity-30" onClick={() => move(idx, 1)} disabled={idx === items.length - 1}>Move down</button>
                        <button className="rounded px-1.5 py-0.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" onClick={() => (setItems((l) => l.filter((x) => x.key !== it.key)), setDirty(true))}>Remove</button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
            <Field label="Notes for the client" hint="Shown on the quotation above the terms.">
              <Textarea rows={4} value={h.notes} onChange={(e) => set('notes', e.target.value)} disabled={ro} />
            </Field>
            <Field label="Terms & conditions">
              <Textarea rows={4} value={h.terms} onChange={(e) => set('terms', e.target.value)} disabled={ro} />
            </Field>
          </Card>
        </div>

        <div className="space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Card className="p-4">
            <h3 className="mb-3 text-sm font-semibold">Summary</h3>
            <div className="space-y-2 text-sm">
              <Row label="Subtotal" value={inr(t.subtotal)} />
              <div className="flex items-center gap-2">
                <span className="text-muted">Discount</span>
                <Segmented size="sm" value={h.discount_type} onChange={(v) => set('discount_type', v)} options={[['flat', '₹'], ['percent', '%']]} />
                <Input value={h.discount_value} onChange={(e) => set('discount_value', e.target.value)} inputMode="decimal" className="ml-auto w-24 py-1 text-right" aria-label="Discount" invalid={!!errors.discount_value} disabled={ro} />
              </div>
              {t.discount > 0 && <Row label="" value={'− ' + inr(t.discount)} muted />}
              <div className="flex items-center gap-2">
                <span className="text-muted">GST %</span>
                <Input value={h.gst_rate} onChange={(e) => set('gst_rate', e.target.value)} inputMode="decimal" className="ml-auto w-24 py-1 text-right" aria-label="GST rate" invalid={!!errors.gst_rate} disabled={ro} />
              </div>
              {t.gst > 0 && (
                <>
                  <Row label={`CGST ${num(h.gst_rate) / 2}%`} value={inr(half)} muted />
                  <Row label={`SGST ${num(h.gst_rate) / 2}%`} value={inr(r2(t.gst - half))} muted />
                </>
              )}
              <div className="flex items-center justify-between border-t border-line pt-3">
                <span className="font-semibold">Total</span>
                <span className="tabular text-xl font-semibold">{inr(t.total)}</span>
              </div>
            </div>
            {editable && (
              <Button variant="primary" className="mt-4 hidden w-full lg:flex" loading={saving} onClick={save} disabled={!isNew && !dirty}>
                {isNew ? 'Create quotation' : dirty ? 'Save changes' : 'Saved'}
              </Button>
            )}
          </Card>

          {editable && (
            <Card>
              <div className="border-b border-line p-3">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
                  <Input value={picker} onChange={(e) => setPicker(e.target.value)} placeholder="Search services…" className="pl-8" />
                </div>
              </div>
              <div className="scrollbar-thin max-h-[420px] overflow-y-auto p-2">
                {services.isLoading ? (
                  <p className="p-3 text-sm text-muted">Loading catalogue…</p>
                ) : !services.data?.length ? (
                  <p className="p-3 text-sm text-muted">
                    No services yet. {can('settings.edit') ? <Link to="/settings?tab=services" className="text-accent-ink">Add your price list</Link> : 'Ask an admin to add the price list.'}
                  </p>
                ) : (
                  Object.entries(grouped).map(([cat, list]) => (
                    <div key={cat} className="mb-2">
                      <p className="px-2 pb-1 pt-2 text-2xs font-semibold uppercase tracking-wide text-faint">{config.service_categories[cat] || cat}</p>
                      {list.map((s) => {
                        const added = items.some((i) => i.service_id === s.id);
                        return (
                          <button key={s.id} onClick={() => addService(s)} className={clsx('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-subtle', added && 'text-muted')}>
                            <Plus className="h-3.5 w-3.5 shrink-0 text-accent-ink" />
                            <span className="min-w-0 flex-1 truncate">{s.name}</span>
                            <span className="tabular shrink-0 text-xs text-muted">{inr(s.price)}{s.unit ? '/' + s.unit : ''}</span>
                          </button>
                        );
                      })}
                    </div>
                  ))
                )}
              </div>
            </Card>
          )}
        </div>
      </div>

      {editable && (
        <div className="fixed inset-x-0 bottom-14 z-30 flex items-center gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:hidden">
          <div className="min-w-0 flex-1">
            <p className="text-2xs text-muted">Total</p>
            <p className="tabular truncate font-semibold">{inr(t.total)}</p>
          </div>
          <Button variant="primary" loading={saving} onClick={save} disabled={!isNew && !dirty}>{isNew ? 'Create' : dirty ? 'Save' : 'Saved'}</Button>
        </div>
      )}

      {q && <WhatsAppModal open={wa} onClose={() => (setWa(false), refresh())} ctx={{ quotation_id: q.id, lead_id: q.lead_id || undefined, client_id: q.client_id || undefined }} template="quotation" title="Share quotation on WhatsApp" />}
    </div>
  );
}

function Row({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
  return (
    <div className={clsx('flex items-center justify-between', muted && 'text-xs text-muted')}>
      <span className={muted ? '' : 'text-muted'}>{label}</span>
      <span className="tabular">{value}</span>
    </div>
  );
}
