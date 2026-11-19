import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowLeft, ArrowRight, CalendarCheck, Copy, Download, Eye, ExternalLink, FileText, Link2, MoreHorizontal, Package, Plus, RefreshCw, Save, Search, Trash2 } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, fmtDateTime, fromNow, inr, today, dayjs } from '@/lib/format';
import { quoteTone } from '@/lib/tones';
import { Badge, Button, Card, CardHeader, ErrorState, Field, Input, Menu, MenuItem, Modal, PageHeader, PageLoader, Select, Textarea, Toggle, useUi, Segmented } from '@/components/ui';
import { Timeline, WhatsAppModal } from '@/components/shared';
import { EventsEditor, LinesEditor, QuotePreview, ScheduleEditor, Stepper, TextBlock, eventFromApi, gstLines, k, linesFrom, milestonesFrom, newEvent, num, quoteTotals, r2, scheduleAmounts, type Ev, type Item, type Milestone } from './parts';

export { quoteTotals };

type Head = {
  title: string;
  customer_name: string;
  customer_phone: string;
  customer_email: string;
  event_type: string;
  valid_until: string;
  discount_type: 'flat' | 'percent';
  discount_value: string;
  gst_rate: string;
  gst_type: 'intra' | 'inter';
  show_prices: boolean;
  notes: string;
  terms: string;
  intro: string;
  closing: string;
  lead_id: number | null;
  client_id: number | null;
  package_id: number | null;
};

const STEPS: [string, string][] = [
  ['details', 'Client & events'],
  ['services', 'Services & packages'],
  ['deliverables', 'Deliverables & payments'],
  ['review', 'Terms & review'],
];
const STEP_OF: Record<string, string> = { title: 'details', customer_name: 'details', customer_phone: 'details', customer_email: 'details', valid_until: 'details', events: 'details', items: 'services', discount_value: 'services', gst_rate: 'services', deliverables: 'deliverables', exclusions: 'deliverables', payment_schedule: 'deliverables' };

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
  const ro = !editable;

  const existing = useQuery({ queryKey: ['quotation', id], queryFn: () => api.get(`quotations/${id}`), enabled: !isNew });
  const lead = useQuery({ queryKey: ['lead', leadId], queryFn: () => api.get(`leads/${leadId}`), enabled: isNew && !!leadId });
  const client = useQuery({ queryKey: ['client', clientId], queryFn: () => api.get(`clients/${clientId}`), enabled: isNew && !!clientId && !leadId });
  const services = useQuery({ queryKey: ['services', 'active'], queryFn: () => api.get<any[]>('services', { active: 1 }) });
  const packages = useQuery({ queryKey: ['quote-packages', 'active'], queryFn: () => api.get<any[]>('quote-packages', { active: 1 }) });
  const activity = useQuery({ queryKey: ['quotation-activity', id], queryFn: () => api.get<any[]>(`quotations/${id}/activity`), enabled: !isNew });

  const settings = studio.settings || {};
  const blank = (): Head => ({
    title: '',
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    event_type: '',
    valid_until: dayjs().add(Number(settings.quote_validity || 15), 'day').format('YYYY-MM-DD'),
    discount_type: 'flat',
    discount_value: '0',
    gst_rate: settings.gst_enabled === false ? '0' : String(settings.gst_rate ?? 18),
    gst_type: settings.gst_type === 'inter' ? 'inter' : 'intra',
    show_prices: settings.quote_show_prices !== false,
    notes: '',
    terms: settings.quote_terms || '',
    intro: settings.quote_intro || '',
    closing: settings.quote_closing || '',
    lead_id: null,
    client_id: null,
    package_id: null,
  });
  const [h, setH] = useState<Head>(blank);
  const [items, setItems] = useState<Item[]>([]);
  const [events, setEvents] = useState<Ev[]>([]);
  const [deliverables, setDeliverables] = useState<string[]>(() => linesFrom(settings.quote_deliverables));
  const [exclusions, setExclusions] = useState<string[]>(() => linesFrom(settings.quote_exclusions));
  const [schedule, setSchedule] = useState<Milestone[]>(() => milestonesFrom(settings.quote_payment_schedule));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [picker, setPicker] = useState('');
  const [pickTab, setPickTab] = useState<'packages' | 'services'>('packages');
  const [wa, setWa] = useState(false);
  const [savePkg, setSavePkg] = useState(false);
  const [ready, setReady] = useState(isNew && !leadId && !clientId);
  const [step, setStep] = useState(isNew ? 'details' : 'review');

  const touch = () => setDirty(true);

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
      valid_until: q.valid_until || '',
      discount_type: q.discount_type || 'flat',
      discount_value: String(q.discount_value ?? 0),
      gst_rate: String(q.gst_rate ?? 0),
      gst_type: q.gst_type === 'inter' ? 'inter' : 'intra',
      show_prices: q.show_prices !== 0,
      notes: q.notes || '',
      terms: q.terms || '',
      intro: q.intro || '',
      closing: q.closing || '',
      lead_id: q.lead_id,
      client_id: q.client_id,
      package_id: q.package_id || null,
    });
    setItems(q.items.map((i: any) => ({ key: k(), service_id: i.service_id, category: i.category, name: i.name, description: i.description || '', qty: String(i.qty), rate: String(i.rate), kind: i.kind || 'service', unit: i.unit || '' })));
    const evs = (q.events || []).map(eventFromApi);
    // Quotations from before v0.6.0 only have one date / venue.
    if (!evs.length && (q.event_date || q.venue)) evs.push(newEvent({ name: q.event_type ? config.event_types[q.event_type] : 'Event', event_type: q.event_type || '', event_date: q.event_date || '', venue: q.venue || '' }));
    setEvents(evs);
    setDeliverables(q.deliverables || []);
    setExclusions(q.exclusions || []);
    setSchedule(milestonesFrom(q.payment_schedule));
    setDirty(false);
    setReady(true);
  }, [existing.data, config.event_types]);

  useEffect(() => {
    const l = lead.data;
    if (!l) return;
    const et = l.event_type ? config.event_types[l.event_type] : '';
    setH((p) => ({ ...p, lead_id: l.id, client_id: l.client_id || null, title: `${et || 'Photography'} coverage – ${l.name}`, customer_name: l.name, customer_phone: l.phone || '', customer_email: l.email || '', event_type: l.event_type || '' }));
    // One event day per date in the lead's range (max 10).
    if (l.event_date) {
      const start = dayjs(l.event_date);
      const end = l.event_end_date ? dayjs(l.event_end_date) : start;
      const n = Math.min(10, Math.max(1, end.diff(start, 'day') + 1));
      setEvents(Array.from({ length: n }, (_, i) => newEvent({ name: (et || 'Event') + (n > 1 ? ` – Day ${i + 1}` : ''), event_type: l.event_type || '', event_date: start.add(i, 'day').format('YYYY-MM-DD'), venue: l.venue || '', city: l.city || '' })));
    } else if (l.venue || l.city) setEvents([newEvent({ name: et || 'Event', event_type: l.event_type || '', venue: l.venue || '', city: l.city || '' })]);
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
    touch();
    if (errors[key as string]) setErrors((e) => ({ ...e, [key]: '' }));
  };
  const setItem = (key: string, patch: Partial<Item>) => {
    setItems((list) => list.map((i) => (i.key === key ? { ...i, ...patch } : i)));
    touch();
  };
  const addService = (s: any, kind: Item['kind'] = 'service') => {
    setItems((list) => [...list, { key: k(), service_id: s.id, category: s.category, name: s.name, description: s.description || '', qty: '1', rate: String(s.price), kind, unit: s.unit || '' }]);
    touch();
  };
  const addCustom = (kind: Item['kind'] = 'service') => {
    setItems((list) => [...list, { key: k(), service_id: null, category: null, name: '', description: '', qty: '1', rate: '', kind, unit: '' }]);
    touch();
  };
  const applyPackage = (p: any) => {
    setItems((list) => [...list, ...p.items.map((i: any) => ({ key: k(), service_id: i.service_id, category: i.category, name: i.name, description: i.description || '', qty: String(i.qty), rate: String(i.rate), kind: 'package' as const, unit: i.unit || '' }))]);
    setDeliverables((d) => [...d, ...(p.deliverables || []).filter((x: string) => !d.some((y) => y.toLowerCase() === x.toLowerCase()))]);
    if (p.coverage && Object.keys(p.coverage).length) setEvents((evs) => evs.map((e) => (Object.keys(e.coverage).length ? e : { ...e, coverage: { ...p.coverage } })));
    setH((x) => ({ ...x, package_id: p.id, event_type: x.event_type || p.event_type || '' }));
    touch();
    toast(`Added ${p.name}: ${p.items.length} services, deliverables and team per day`);
  };
  const move = (idx: number, d: number) => {
    setItems((list) => {
      const n = [...list];
      const j = idx + d;
      if (j < 0 || j >= n.length) return list;
      [n[idx], n[j]] = [n[j], n[idx]];
      return n;
    });
    touch();
  };

  const t = useMemo(() => quoteTotals(items, h.discount_type, num(h.discount_value), num(h.gst_rate)), [items, h.discount_type, h.discount_value, h.gst_rate]);
  const amounts = useMemo(() => scheduleAmounts(schedule, t.total), [schedule, t.total]);
  const schedSum = r2(schedule.reduce((s, m) => s + num(m.percent), 0));

  const grouped = useMemo(() => {
    const g: Record<string, any[]> = {};
    const s = picker.trim().toLowerCase();
    (services.data || []).filter((x) => !s || x.name.toLowerCase().includes(s)).forEach((x) => (g[x.category] ||= []).push(x));
    return g;
  }, [services.data, picker]);
  const pkgList = useMemo(() => {
    const s = picker.trim().toLowerCase();
    return (packages.data || []).filter((p) => !s || p.name.toLowerCase().includes(s)).sort((a, b) => Number(b.event_type === h.event_type) - Number(a.event_type === h.event_type));
  }, [packages.data, picker, h.event_type]);

  const validateAll = () => {
    const e: Record<string, string> = {};
    if (!h.title.trim()) e.title = 'Give the quotation a title.';
    if (!h.customer_name.trim()) e.customer_name = 'Customer name is required.';
    if (h.customer_phone && h.customer_phone.replace(/\D/g, '').length < 10) e.customer_phone = 'Enter a valid phone number.';
    if (h.customer_email && !/^\S+@\S+\.\S+$/.test(h.customer_email)) e.customer_email = 'Enter a valid email.';
    if (isNew && h.valid_until && h.valid_until < today()) e.valid_until = 'Validity date cannot be in the past.';
    if (num(h.gst_rate) < 0 || num(h.gst_rate) > 28) e.gst_rate = 'GST must be between 0 and 28%.';
    if (num(h.discount_value) < 0) e.discount_value = 'Discount cannot be negative.';
    if (h.discount_type === 'percent' && num(h.discount_value) > 100) e.discount_value = 'Discount cannot be more than 100%.';
    events.forEach((ev, n) => {
      if (!ev.name.trim()) e.events ||= `Day ${n + 1} needs an event name.`;
      else if (ev.start_time && ev.end_time && ev.start_time === ev.end_time) e.events ||= `Day ${n + 1}: end time must differ from start time.`;
    });
    if (!items.length) e.items = 'Add at least one service or package.';
    items.forEach((i, n) => {
      if (!i.name.trim()) e.items ||= `Line ${n + 1} needs a name.`;
      else if (num(i.qty) <= 0) e.items ||= `Line ${n + 1}: quantity must be more than 0.`;
      else if (i.rate === '' || num(i.rate) < 0) e.items ||= `Line ${n + 1}: enter a rate.`;
    });
    schedule.forEach((m, n) => {
      if (!m.label.trim()) e.payment_schedule ||= `Milestone ${n + 1} needs a name.`;
      else if (num(m.percent) <= 0 || num(m.percent) > 100) e.payment_schedule ||= `Milestone ${n + 1}: enter a percentage.`;
    });
    if (schedule.length && Math.abs(schedSum - 100) > 0.01) e.payment_schedule ||= `Milestones add up to ${schedSum}%. They must add up to 100%.`;
    return e;
  };

  const save = async (): Promise<number | null> => {
    const e = validateAll();
    setErrors(e);
    const first = Object.keys(e).find((x) => e[x]);
    if (first) {
      setStep(STEP_OF[first] || 'review');
      toast(e[first], 'error');
      return null;
    }
    const body = {
      ...h,
      discount_value: num(h.discount_value),
      gst_rate: num(h.gst_rate),
      show_prices: h.show_prices ? 1 : 0,
      items: items.map(({ key, ...i }) => ({ ...i, qty: num(i.qty), rate: num(i.rate), unit: i.unit || null })),
      events: events.map(({ key, ...ev }) => ev),
      deliverables,
      exclusions,
      payment_schedule: schedule.map(({ key, ...m }) => ({ ...m, percent: num(m.percent) })),
    };
    setSaving(true);
    try {
      const r = isNew ? await api.post('quotations', body) : await api.put(`quotations/${id}`, body);
      ['quotations', 'lead', 'dashboard'].forEach((x) => qc.invalidateQueries({ queryKey: [x] }));
      setDirty(false);
      if (isNew) {
        toast(`Quotation ${r.number} created`);
        nav(`/quotations/${r.id}`, { replace: true });
        setStep('review');
      } else {
        await qc.invalidateQueries({ queryKey: ['quotation', id] });
        qc.invalidateQueries({ queryKey: ['quotation-activity', id] });
        toast('Quotation saved');
      }
      return r.id;
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(err.errors);
        const f = Object.keys(err.errors)[0];
        if (f) setStep(STEP_OF[f] || step);
      }
      toast((err as Error).message, 'error');
      return null;
    } finally {
      setSaving(false);
    }
  };

  const q = existing.data;
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['quotation', id] });
    qc.invalidateQueries({ queryKey: ['quotation-activity', id] });
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
    if (!(await ensureSaved())) return;
    try {
      await navigator.clipboard.writeText(q.public_url);
      toast('Client link copied. The client can view, download and accept this quotation.');
    } catch {
      window.prompt('Copy this link', q.public_url);
    }
    if (q.status === 'draft' && editable) setStatus('sent');
  };
  const newLink = async () => {
    if (!(await confirm({ title: 'Create a new client link?', message: 'The link you shared before will stop working. Share the new link with the client.', confirmLabel: 'Create new link' }))) return;
    try {
      await api.post(`quotations/${id}/new-link`, {});
      refresh();
      toast('New client link created');
    } catch (e: any) {
      toast(e.message, 'error');
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

  const si = STEPS.findIndex(([s]) => s === step);
  const done: Record<string, boolean> = {
    details: !!(h.title.trim() && h.customer_name.trim()),
    services: items.length > 0,
    deliverables: deliverables.length > 0 || schedule.length > 0,
    review: !isNew && !dirty,
  };

  return (
    <div className="pb-28 lg:pb-0">
      <PageHeader
        back={<Link to="/quotations" className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> Quotations</Link>}
        title={
          <span className="flex items-center gap-2">
            {isNew ? 'New quotation' : q.number}
            {q && <Badge tone={quoteTone[q.status]}>{config.quotation_statuses[q.status]}</Badge>}
            {dirty && !isNew && <Badge tone="amber">Unsaved</Badge>}
          </span>
        }
        subtitle={
          q ? (
            <>
              Created {fmtDate(q.created_at)}
              {q.sent_at && ` · Sent ${fmtDateTime(q.sent_at)}`}
              {q.view_count > 0 && ` · Opened ${q.view_count}× (last ${fromNow(q.last_viewed_at)})`}
              {q.responded_at && ` · Responded ${fmtDateTime(q.responded_at)}`}
              {q.lead_id ? <> · <Link to={`/leads/${q.lead_id}`} className="text-accent-ink">Open lead</Link></> : null}
            </>
          ) : h.lead_id ? (
            <>For lead <Link to={`/leads/${h.lead_id}`} className="text-accent-ink">{h.customer_name}</Link></>
          ) : (
            'Build it step by step. The total and the client preview update as you go.'
          )
        }
        actions={
          q && (
            <>
              <Button icon={<FileText className="h-4 w-4" />} onClick={() => pdf(false)}>PDF</Button>
              <Button icon={<Link2 className="h-4 w-4" />} onClick={copyLink}>Copy link</Button>
              <Button variant="success" onClick={() => setWa(true)} disabled={dirty} title={dirty ? 'Save first' : undefined}>WhatsApp</Button>
              {q.booking_id ? (
                <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} onClick={() => nav(`/bookings/${q.booking_id}`)}>Open booking</Button>
              ) : (
                can('bookings.edit') && q.status !== 'rejected' && <Button variant="primary" icon={<CalendarCheck className="h-4 w-4" />} onClick={convert}>Convert to booking</Button>
              )}
              <Menu trigger={() => <Button icon={<MoreHorizontal className="h-4 w-4" />} aria-label="More actions" />}>
                {(close) => (
                  <>
                    <MenuItem icon={<Download className="h-4 w-4" />} onClick={() => (close(), pdf(true))}>Download PDF</MenuItem>
                    <MenuItem icon={<ExternalLink className="h-4 w-4" />} onClick={() => (close(), window.open(q.public_url, '_blank'))}>Open client page</MenuItem>
                    {editable && <MenuItem icon={<RefreshCw className="h-4 w-4" />} onClick={() => (close(), newLink())}>New client link</MenuItem>}
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

      <Stepper steps={STEPS} value={step} onChange={setStep} done={done} />

      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <div className="min-w-0 space-y-5">
          {q && (q.status === 'accepted' || q.status === 'rejected' || q.client_note) && (
            <div className={clsx('rounded-xl border px-4 py-3 text-sm', q.status === 'accepted' ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-line bg-subtle')}>
              <p className="font-medium">
                {q.status === 'accepted' ? `Accepted${q.accepted_name ? ' by ' + q.accepted_name : ''}` : q.status === 'rejected' ? 'Declined by the client' : 'Message from the client'}
                {q.responded_at && <span className="font-normal opacity-80"> · {fmtDateTime(q.responded_at)}</span>}
              </p>
              {q.client_note && <p className="mt-1 whitespace-pre-line">“{q.client_note}”</p>}
              {q.status === 'accepted' && !q.booking_id && can('bookings.edit') && <Button size="sm" variant="primary" className="mt-2" icon={<CalendarCheck className="h-4 w-4" />} onClick={convert}>Convert to booking</Button>}
            </div>
          )}

          {step === 'details' && (
            <>
              <Card className="p-4 sm:p-5">
                <h3 className="mb-4 text-sm font-semibold">Client</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Quotation title" required error={errors.title} className="sm:col-span-2">
                    <Input value={h.title} onChange={(e) => set('title', e.target.value)} placeholder="e.g. Wedding photography & films – Sharma family" invalid={!!errors.title} disabled={ro} maxLength={190} />
                  </Field>
                  <Field label="Customer name" required error={errors.customer_name}>
                    <Input value={h.customer_name} onChange={(e) => set('customer_name', e.target.value)} invalid={!!errors.customer_name} disabled={ro} maxLength={190} />
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
                  <Field label="Valid until" error={errors.valid_until} hint={h.valid_until ? `${dayjs(h.valid_until).diff(dayjs(today()), 'day')} days from today` : undefined}>
                    <Input type="date" value={h.valid_until} min={isNew ? today() : undefined} onChange={(e) => set('valid_until', e.target.value)} disabled={ro} invalid={!!errors.valid_until} />
                  </Field>
                </div>
              </Card>
              <Card className="p-4 sm:p-5">
                <div className="mb-4">
                  <h3 className="text-sm font-semibold">Events, dates & venues</h3>
                  <p className="text-xs text-muted">One row per function. Each day can have its own venue and team. These carry over when you convert to a booking.</p>
                </div>
                <EventsEditor value={events} onChange={(v) => (setEvents(v), touch(), errors.events && setErrors((e) => ({ ...e, events: '' })))} disabled={ro} error={errors.events} />
              </Card>
            </>
          )}

          {step === 'services' && (
            <Card>
              <CardHeader
                title="Services"
                subtitle="Pick a package or services on the right. Change any line for this client."
                actions={
                  editable && (
                    <div className="flex gap-2">
                      {can('settings.edit') && items.length > 0 && <Button size="sm" icon={<Save className="h-4 w-4" />} onClick={() => setSavePkg(true)}>Save as package</Button>}
                      <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => addCustom()}>Custom item</Button>
                    </div>
                  )
                }
              />
              {errors.items && <p className="mx-4 mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{errors.items}</p>}
              {items.length === 0 ? (
                <p className="px-4 pb-6 pt-2 text-center text-sm text-muted">No items yet. Pick a package or a service from your catalogue{editable ? ' on the right' : ''}.</p>
              ) : (
                <ul className="divide-y divide-line border-t border-line">
                  {items.map((it, idx) => (
                    <li key={it.key} className="grid grid-cols-12 gap-2 px-4 py-3">
                      <div className="col-span-12 space-y-1.5 sm:col-span-6">
                        <div className="flex gap-2">
                          <Select value={it.kind} onChange={(e) => setItem(it.key, { kind: e.target.value as Item['kind'] })} options={config.quote_item_kinds} className="w-28 shrink-0 py-1 text-xs" aria-label="Item type" disabled={ro} />
                          <Input value={it.name} onChange={(e) => setItem(it.key, { name: e.target.value })} placeholder="Item name" className="font-medium" disabled={ro} maxLength={190} />
                        </div>
                        <Textarea value={it.description} onChange={(e) => setItem(it.key, { description: e.target.value })} rows={1} placeholder="Details (optional)" className="text-xs" disabled={ro} />
                      </div>
                      <div className="col-span-3 sm:col-span-2">
                        <label className="label sm:hidden">Qty{it.unit ? ` (${it.unit})` : ''}</label>
                        <Input value={it.qty} onChange={(e) => setItem(it.key, { qty: e.target.value })} inputMode="decimal" className="text-right" aria-label="Quantity" disabled={ro} />
                        {it.unit && <p className="mt-0.5 hidden text-right text-2xs text-faint sm:block">per {it.unit}</p>}
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
                          <button className="rounded px-1.5 py-0.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" onClick={() => (setItems((l) => l.filter((x) => x.key !== it.key)), touch())}>Remove</button>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              {editable && items.length > 0 && (
                <div className="flex justify-end border-t border-line px-4 py-2">
                  <Button size="sm" variant="ghost" icon={<Plus className="h-4 w-4" />} onClick={() => addCustom('addon')}>Add-on</Button>
                </div>
              )}
            </Card>
          )}

          {step === 'deliverables' && (
            <>
              <Card className="p-4 sm:p-5">
                <h3 className="text-sm font-semibold">What the client will receive</h3>
                <p className="mb-4 text-xs text-muted">Tap a suggestion or type your own. Packages add their deliverables here automatically.</p>
                <LinesEditor value={deliverables} onChange={(v) => (setDeliverables(v), touch())} presets={config.quote_deliverable_presets} placeholder="e.g. 400 edited photos in 30 days" disabled={ro} />
              </Card>
              <Card className="p-4 sm:p-5">
                <h3 className="text-sm font-semibold">Payment plan</h3>
                <p className="mb-4 text-xs text-muted">Amounts follow the total. When converted, the booking gets these milestones as its installments.</p>
                <ScheduleEditor value={schedule} onChange={(v) => (setSchedule(v), touch(), errors.payment_schedule && setErrors((e) => ({ ...e, payment_schedule: '' })))} total={t.total} disabled={ro} error={errors.payment_schedule} />
              </Card>
              <Card className="p-4 sm:p-5">
                <h3 className="text-sm font-semibold">Not included</h3>
                <p className="mb-4 text-xs text-muted">Things the client pays for separately, so there are no surprises later.</p>
                <LinesEditor value={exclusions} onChange={(v) => (setExclusions(v), touch())} placeholder="e.g. Travel outside the city" disabled={ro} />
              </Card>
            </>
          )}

          {step === 'review' && (
            <>
              <Card className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
                <TextBlock label="Introduction" hint="Opens the quotation." value={h.intro} onChange={(v) => set('intro', v)} disabled={ro} />
                <TextBlock label="Closing message" hint="Shown at the end." value={h.closing} onChange={(v) => set('closing', v)} disabled={ro} />
                <TextBlock label="Notes for the client" hint="Shown above the terms." value={h.notes} onChange={(v) => set('notes', v)} rows={4} disabled={ro} />
                <TextBlock label="Terms & conditions" value={h.terms} onChange={(v) => set('terms', v)} rows={4} disabled={ro} />
                <div className="sm:col-span-2">
                  <Toggle checked={h.show_prices} onChange={(v) => set('show_prices', v)} label={<span>Show rate and amount for each line <span className="text-xs text-muted">(off shows only the totals)</span></span>} />
                </div>
              </Card>
              <div>
                <div className="mb-2 flex items-center gap-2 text-xs text-muted"><Eye className="h-3.5 w-3.5" /> What the client sees{dirty ? ' (unsaved changes included)' : ''}</div>
                <QuotePreview d={{ number: q?.number, title: h.title, customer_name: h.customer_name, event_type: h.event_type, valid_until: h.valid_until, intro: h.intro, closing: h.closing, notes: h.notes, terms: h.terms, events, items, deliverables, exclusions, schedule, totals: t, discount_type: h.discount_type, discount_value: num(h.discount_value), gst_rate: num(h.gst_rate), gst_type: h.gst_type, show_prices: h.show_prices }} />
              </div>
              {q && (
                <Card>
                  <CardHeader title="Timeline" subtitle="Edits, shares, client views and responses." />
                  <div className="px-4 pb-4">
                    <Timeline items={(activity.data || []).map((a) => ({ id: a.id, type: a.action.startsWith('status_accepted') ? 'payment' : 'quotation', message: a.summary, created_at: a.created_at, user_name: a.user_name }))} empty="No activity yet." />
                  </div>
                </Card>
              )}
            </>
          )}

          <div className="flex items-center justify-between gap-2">
            <Button icon={<ArrowLeft className="h-4 w-4" />} onClick={() => setStep(STEPS[si - 1][0])} disabled={si === 0}>Back</Button>
            {si < STEPS.length - 1 ? (
              <Button variant="primary" onClick={() => setStep(STEPS[si + 1][0])}>Next: {STEPS[si + 1][1]} <ArrowRight className="ml-1 h-4 w-4" /></Button>
            ) : (
              editable && <Button variant="primary" loading={saving} onClick={save} disabled={!isNew && !dirty}>{isNew ? 'Create quotation' : dirty ? 'Save changes' : 'Saved'}</Button>
            )}
          </div>
        </div>

        <div className="space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Card className="p-4">
            <h3 className="mb-3 text-sm font-semibold">Summary</h3>
            <div className="space-y-2 text-sm">
              <Row label={`Subtotal · ${items.length} line${items.length === 1 ? '' : 's'}`} value={inr(t.subtotal)} />
              <div className="flex items-center gap-2">
                <span className="text-muted">Discount</span>
                <Segmented size="sm" value={h.discount_type} onChange={(v) => set('discount_type', v)} options={[['flat', '₹'], ['percent', '%']]} />
                <Input value={h.discount_value} onChange={(e) => set('discount_value', e.target.value)} inputMode="decimal" className="ml-auto w-24 py-1 text-right" aria-label="Discount" invalid={!!errors.discount_value} disabled={ro} />
              </div>
              {errors.discount_value && <p className="text-xs text-red-600">{errors.discount_value}</p>}
              {t.discount > 0 && <Row label="" value={'− ' + inr(t.discount)} muted />}
              <div className="flex items-center gap-2">
                <span className="text-muted">GST %</span>
                <Segmented size="sm" value={h.gst_type} onChange={(v) => set('gst_type', v)} options={[['intra', 'CGST+SGST'], ['inter', 'IGST']]} />
                <Input value={h.gst_rate} onChange={(e) => set('gst_rate', e.target.value)} inputMode="decimal" className="ml-auto w-16 py-1 text-right" aria-label="GST rate" invalid={!!errors.gst_rate} disabled={ro} />
              </div>
              {errors.gst_rate && <p className="text-xs text-red-600">{errors.gst_rate}</p>}
              {gstLines(num(h.gst_rate), t.gst, h.gst_type).map(([l, v]) => <Row key={l} label={l} value={inr(v)} muted />)}
              <div className="flex items-center justify-between border-t border-line pt-3">
                <span className="font-semibold">Total</span>
                <span className="tabular text-xl font-semibold">{inr(t.total)}</span>
              </div>
              {schedule.length > 0 && (
                <div className="space-y-1 rounded-lg bg-subtle/60 p-2.5">
                  {schedule.map((m, i) => <Row key={m.key} label={`${m.label || 'Milestone'} (${num(m.percent)}%)`} value={inr(amounts[i])} muted />)}
                  {Math.abs(schedSum - 100) > 0.01 && <p className="text-2xs text-red-600">Milestones add up to {schedSum}%</p>}
                </div>
              )}
            </div>
            {editable && (
              <Button variant="primary" className="mt-4 hidden w-full lg:flex" loading={saving} onClick={save} disabled={!isNew && !dirty}>
                {isNew ? 'Create quotation' : dirty ? 'Save changes' : 'Saved'}
              </Button>
            )}
          </Card>

          {editable && step === 'services' && (
            <Card>
              <div className="space-y-2 border-b border-line p-3">
                <Segmented value={pickTab} onChange={setPickTab} options={[['packages', `Packages${packages.data ? ` (${packages.data.length})` : ''}`], ['services', 'Services']]} />
                <div className="relative">
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
                  <Input value={picker} onChange={(e) => setPicker(e.target.value)} placeholder={pickTab === 'packages' ? 'Search packages…' : 'Search services…'} className="pl-8" />
                </div>
              </div>
              <div className="scrollbar-thin max-h-[460px] overflow-y-auto p-2">
                {pickTab === 'packages' ? (
                  packages.isLoading ? (
                    <p className="p-3 text-sm text-muted">Loading packages…</p>
                  ) : packages.error ? (
                    <p className="p-3 text-sm text-red-600">Could not load packages.</p>
                  ) : !pkgList.length ? (
                    <p className="p-3 text-sm text-muted">
                      No packages yet. {can('settings.edit') ? <>Add lines, then use <b>Save as package</b>, or <Link to="/settings?tab=packages" className="text-accent-ink">manage packages</Link>.</> : 'Ask an admin to set up packages.'}
                    </p>
                  ) : (
                    pkgList.map((p) => (
                      <button key={p.id} onClick={() => applyPackage(p)} className={clsx('mb-1.5 w-full rounded-xl border p-3 text-left transition-colors hover:border-accent hover:bg-accent-soft/50', h.package_id === p.id ? 'border-accent' : 'border-line')}>
                        <div className="flex items-start gap-2">
                          <Package className="mt-0.5 h-4 w-4 shrink-0 text-accent-ink" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">{p.name}</p>
                            <p className="text-xs text-muted">{p.items.length} services{p.event_type ? ` · ${config.event_types[p.event_type]}` : ''}</p>
                          </div>
                          <span className="tabular shrink-0 text-sm font-semibold">{inr(p.total)}</span>
                        </div>
                        {p.description && <p className="mt-1.5 line-clamp-2 text-xs text-muted">{p.description}</p>}
                      </button>
                    ))
                  )
                ) : services.isLoading ? (
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
                          <div key={s.id} className={clsx('group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-subtle', added && 'text-muted')}>
                            <button onClick={() => addService(s)} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                              <Plus className="h-3.5 w-3.5 shrink-0 text-accent-ink" />
                              <span className="min-w-0 flex-1 truncate">{s.name}</span>
                              <span className="tabular shrink-0 text-xs text-muted">{inr(s.price)}{s.unit ? '/' + s.unit : ''}</span>
                            </button>
                            <button onClick={() => addService(s, 'addon')} className="hidden shrink-0 rounded border border-line px-1.5 text-2xs text-muted hover:text-ink group-hover:inline" title="Add as an add-on">Add-on</button>
                          </div>
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
            <p className="text-2xs text-muted">Step {si + 1} of {STEPS.length} · Total</p>
            <p className="tabular truncate font-semibold">{inr(t.total)}</p>
          </div>
          {si < STEPS.length - 1 && <Button onClick={() => setStep(STEPS[si + 1][0])}>Next</Button>}
          <Button variant="primary" loading={saving} onClick={save} disabled={!isNew && !dirty}>{isNew ? 'Create' : dirty ? 'Save' : 'Saved'}</Button>
        </div>
      )}

      {q && <WhatsAppModal open={wa} onClose={() => (setWa(false), refresh())} ctx={{ quotation_id: q.id, lead_id: q.lead_id || undefined, client_id: q.client_id || undefined }} template="quotation" title="Share quotation on WhatsApp" />}
      <SavePackageModal open={savePkg} onClose={() => setSavePkg(false)} items={items} deliverables={deliverables} coverage={events[0]?.coverage || {}} eventType={h.event_type} onSaved={(p) => (qc.invalidateQueries({ queryKey: ['quote-packages'] }), setH((x) => ({ ...x, package_id: p.id })), touch())} />
    </div>
  );
}

function SavePackageModal({ open, onClose, items, deliverables, coverage, eventType, onSaved }: { open: boolean; onClose: () => void; items: Item[]; deliverables: string[]; coverage: Record<string, number>; eventType: string; onSaved: (p: any) => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const [name, setName] = useState('');
  const [type, setType] = useState(eventType);
  const [desc, setDesc] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  useEffect(() => {
    if (open) {
      setName('');
      setType(eventType);
      setDesc('');
      setErr('');
    }
  }, [open, eventType]);
  const submit = async () => {
    if (!name.trim()) return setErr('Give the package a name.');
    const bad = items.find((i) => !i.name.trim() || num(i.qty) <= 0 || i.rate === '');
    if (bad) return setErr('Every line needs a name, a quantity and a rate before saving as a package.');
    setBusy(true);
    try {
      const p = await api.post('quote-packages', { name: name.trim(), event_type: type || null, description: desc, deliverables, coverage, items: items.map((i) => ({ service_id: i.service_id || null, category: i.category || null, name: i.name, description: i.description || '', qty: num(i.qty), rate: num(i.rate), unit: i.unit || null })) });
      toast(`Package ${p.name} saved. It is now available on every new quotation.`);
      onSaved(p);
      onClose();
    } catch (e: any) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open={open} onClose={onClose} title="Save as package" subtitle={`${items.length} lines, ${deliverables.length} deliverables and the team of the first day are saved for reuse.`} footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={submit}>Save package</Button></>}>
      <div className="space-y-4">
        <Field label="Package name" required error={err && !name.trim() ? err : undefined}><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Wedding – Signature" maxLength={190} autoFocus /></Field>
        <Field label="Best for"><Select value={type} onChange={(e) => setType(e.target.value)} options={config.event_types} placeholder="Any event" /></Field>
        <Field label="Short description"><Textarea rows={2} value={desc} onChange={(e) => setDesc(e.target.value)} /></Field>
        {err && name.trim() && <p className="text-xs text-red-600">{err}</p>}
      </div>
    </Modal>
  );
}

function Row({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
  return (
    <div className={clsx('flex items-center justify-between gap-2', muted && 'text-xs text-muted')}>
      <span className={clsx('min-w-0 truncate', !muted && 'text-muted')}>{label}</span>
      <span className="tabular shrink-0">{value}</span>
    </div>
  );
}
