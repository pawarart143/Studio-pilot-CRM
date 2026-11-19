import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlertTriangle, ArrowLeft, LayoutTemplate, PackageCheck, Plus, Trash2, UserPlus, X } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTeam } from '@/lib/data';
import { useDebounce } from '@/lib/hooks';
import { dayjs, fmtDate, fmtTime, inr, phoneDisplay, today } from '@/lib/format';
import { Avatar, Button, Card, CardHeader, ErrorState, Field, Input, PageHeader, PageLoader, SearchInput, Select, Textarea, useUi } from '@/components/ui';
import { ServicesEditor, serviceFromApi, serviceToApi, validateServices, type ServiceRow } from '@/components/services';

type TeamRow = { member_id: number; role: string };
type Ev = { key: string; id?: number; name: string; event_type: string; event_date: string; start_time: string; end_time: string; venue: string; city: string; notes: string; team: TeamRow[] };
type Inst = { key: string; id?: number; label: string; amount: string; due_date: string };
type Client = { name: string; phone: string; email: string; city: string };
type Dl = { key: string; name: string; description: string; assigned_to: string; expected_date: string; days: number | null; touched: boolean };

let seq = 0;
const k = () => 'k' + ++seq;
const num = (v: string | number | null | undefined) => {
  const n = parseFloat(String(v ?? '').replace(/[₹,\s]/g, ''));
  return isFinite(n) ? n : 0;
};
const hm = (t?: string | null) => (t ? t.slice(0, 5) : '');
const newEvent = (p: Partial<Ev> = {}): Ev => ({ key: k(), name: '', event_type: '', event_date: '', start_time: '', end_time: '', venue: '', city: '', notes: '', team: [], ...p });

const defaultRole = (memberRole: string) => (memberRole === 'cinematographer' ? 'cinematographer' : 'photographer');

export default function BookingForm() {
  const { id } = useParams();
  const isEdit = !!id;
  const [sp] = useSearchParams();
  const leadId = sp.get('lead_id');
  const quoteId = sp.get('quotation_id');
  const clientParam = sp.get('client_id');
  const nav = useNavigate();
  const qc = useQueryClient();
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const team = useTeam();
  const crew = (team.data || []).filter((m) => m.status === 'active');

  const existing = useQuery({ queryKey: ['booking', id], queryFn: () => api.get(`bookings/${id}`), enabled: isEdit });
  const preview = useQuery({ queryKey: ['convert', leadId], queryFn: () => api.get(`leads/${leadId}/convert`), enabled: !isEdit && !!leadId });
  const quote = useQuery({ queryKey: ['quotation', quoteId], queryFn: () => api.get(`quotations/${quoteId}`), enabled: !isEdit && !!quoteId });
  const clientQ = useQuery({ queryKey: ['client', clientParam], queryFn: () => api.get(`clients/${clientParam}`), enabled: !isEdit && !!clientParam });

  const [ready, setReady] = useState(false);
  const [clientId, setClientId] = useState<number | null>(null);
  const [clientLabel, setClientLabel] = useState('');
  const [newClient, setNewClient] = useState<Client | null>(null);
  const [lead, setLead] = useState<number | null>(null);
  const [quotationId, setQuotationId] = useState<number | null>(null);
  const [h, setH] = useState({ title: '', event_type: '', package_details: '', total_amount: '', confirmed_on: today(), delivery_due: '', special_instructions: '' });
  const [events, setEvents] = useState<Ev[]>([newEvent()]);
  const [inst, setInst] = useState<Inst[]>([]);
  const [adv, setAdv] = useState({ amount: '', paid_on: today(), method: 'upi', transaction_ref: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [conflicts, setConflicts] = useState<any[]>([]);
  const [services, setServices] = useState<ServiceRow[]>([]);
  const [svcErrors, setSvcErrors] = useState<Record<number, string>>({});
  const [dls, setDls] = useState<Dl[]>([]);
  const [tplId, setTplId] = useState('');
  const canDl = !isEdit && can('deliverables.edit');
  const templates = useQuery({ queryKey: ['deliverable-templates', 'active'], queryFn: () => api.get<any[]>('deliverable-templates', { active: 1 }), enabled: canDl });

  // ---------- Prefill ----------
  useEffect(() => {
    if (isEdit) {
      const b = existing.data;
      if (!b) return;
      setClientId(b.client_id);
      setClientLabel(`${b.client?.name} · ${phoneDisplay(b.client?.phone)}`);
      setLead(b.lead_id);
      setQuotationId(b.quotation_id);
      setH({ title: b.title, event_type: b.event_type || '', package_details: b.package_details || '', total_amount: String(b.total_amount ?? ''), confirmed_on: b.confirmed_on || '', delivery_due: b.delivery_due || '', special_instructions: b.special_instructions || '' });
      setEvents(b.events.map((e: any) => newEvent({ id: e.id, name: e.name, event_type: e.event_type || '', event_date: e.event_date, start_time: hm(e.start_time), end_time: hm(e.end_time), venue: e.venue || '', city: e.city || '', notes: e.notes || '', team: e.team.map((t: any) => ({ member_id: t.member_id, role: t.role })) })));
      setInst((b.payment?.installments || []).map((i: any) => ({ key: k(), id: i.id, label: i.label, amount: String(i.amount), due_date: i.due_date || '' })));
      setServices((b.services || []).map(serviceFromApi));
      setReady(true);
      return;
    }
    if (leadId && !preview.data) return;
    if (quoteId && !quote.data) return;
    if (clientParam && !clientQ.data) return;
    const p = preview.data;
    const q = quote.data;
    let total = '';
    if (p) {
      setLead(p.lead_id);
      setQuotationId(p.quotation_id);
      if (p.client_id) {
        setClientId(p.client_id);
        setClientLabel(`${p.client.name} · ${phoneDisplay(p.client.phone)}`);
      } else setNewClient({ name: p.client.name || '', phone: p.client.phone || '', email: p.client.email || '', city: p.client.city || '' });
      setH((x) => ({ ...x, title: p.title, event_type: p.event_type || '', package_details: p.package_details || '', confirmed_on: p.confirmed_on || today() }));
      total = p.total_amount ? String(p.total_amount) : '';
      if (p.events?.length) setEvents(p.events.map((e: any) => newEvent({ name: e.name, event_type: e.event_type || '', event_date: e.event_date, start_time: hm(e.start_time), end_time: hm(e.end_time), venue: e.venue || '', city: e.city || '', notes: e.notes || '' })));
    }
    // v0.6.0: the quotation's event days and payment plan carry over to the booking.
    const qEvents = (q?.events || []).filter((e: any) => e.event_date);
    const qPlan: any[] = q?.payment_schedule?.length ? q.payment_schedule : p?.payment_schedule || [];
    if (q) {
      setQuotationId(q.id);
      total = String(q.total);
      const pkg = q.items.map((i: any) => i.name + (Number(i.qty) !== 1 ? ` × ${Number(i.qty)}` : '')).join('\n');
      setH((x) => ({ ...x, title: x.title || q.title, event_type: x.event_type || q.event_type || '', package_details: pkg }));
      if (!p) {
        if (q.client_id) {
          setClientId(q.client_id);
          setClientLabel(`${q.customer_name} · ${phoneDisplay(q.customer_phone)}`);
        } else setNewClient({ name: q.customer_name || '', phone: q.customer_phone || '', email: q.customer_email || '', city: '' });
        if (!qEvents.length && q.event_date) setEvents([newEvent({ name: q.event_type ? config.event_types[q.event_type] : 'Event', event_type: q.event_type || '', event_date: q.event_date, venue: q.venue || '' })]);
      }
      // Quoted services become the booking's services (post-production ones don't block shoot dates).
      if (q.items?.length) setServices(q.items.map((i: any) => ({ service_id: i.service_id || null, name: i.name, category: i.category || '', on_site: !['album', 'reels'].includes(i.category || ''), notes: Number(i.qty) !== 1 ? `Qty ${Number(i.qty)}${i.unit ? ' ' + i.unit : ''}` : '', team: [] })));
      if (qEvents.length) setEvents(qEvents.map((e: any) => newEvent({ name: e.name, event_type: e.event_type || '', event_date: e.event_date, start_time: hm(e.start_time), end_time: hm(e.end_time), venue: e.venue || '', city: e.city || '', notes: e.notes || '' })));
    }
    if (clientQ.data && !p && !q) {
      setClientId(clientQ.data.id);
      setClientLabel(`${clientQ.data.name} · ${phoneDisplay(clientQ.data.phone)}`);
      setH((x) => ({ ...x, title: clientQ.data.name }));
    }
    if (total) {
      setH((x) => ({ ...x, total_amount: total }));
      if (qPlan.length) {
        const first = (qEvents[0]?.event_date || q?.event_date || p?.events?.[0]?.event_date || '') as string;
        setInst(qPlan.map((m: any, i: number) => ({ key: k(), label: m.label, amount: String(m.amount), due_date: i === 0 ? today() : i === qPlan.length - 1 ? '' : first ? dayjs(first).subtract(7, 'day').format('YYYY-MM-DD') : '' })));
      } else setInst(split(num(total), [30, 40, 30]));
    }
    setReady(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [existing.data, preview.data, quote.data, clientQ.data]);

  const firstDate = useMemo(() => events.map((e) => e.event_date).filter(Boolean).sort()[0] || '', [events]);
  const lastDate = useMemo(() => events.map((e) => e.event_date).filter(Boolean).sort().pop() || '', [events]);
  const dlDate = (d: Dl) => (d.touched || d.days === null ? d.expected_date : lastDate ? dayjs(lastDate).add(d.days, 'day').format('YYYY-MM-DD') : '');
  const applyTemplate = (tid: string) => {
    const t = (templates.data || []).find((x) => String(x.id) === tid);
    if (!t) return;
    setDls((l) => {
      const have = new Set(l.map((d) => d.name.trim().toLowerCase()));
      return [...l, ...t.items.filter((i: any) => !have.has(i.name.trim().toLowerCase())).map((i: any) => ({ key: k(), name: i.name, description: i.description || '', assigned_to: '', expected_date: '', days: i.days_after_event ?? null, touched: false }))];
    });
    setTplId('');
  };
  const setDl = (key: string, patch: Partial<Dl>) => setDls((l) => l.map((d) => (d.key === key ? { ...d, ...patch } : d)));

  function split(total: number, pcts: number[]): Inst[] {
    const labels = pcts.length === 3 ? ['Advance (booking)', 'Before event', 'On delivery'] : pcts.length === 2 ? ['Advance (booking)', 'On delivery'] : ['Full payment'];
    let left = total;
    return pcts.map((p, i) => {
      const amt = i === pcts.length - 1 ? left : Math.round((total * p) / 100);
      left -= amt;
      const due = i === 0 ? h.confirmed_on || today() : i === pcts.length - 1 ? h.delivery_due || (firstDate ? dayjs(firstDate).add(30, 'day').format('YYYY-MM-DD') : '') : firstDate ? dayjs(firstDate).subtract(7, 'day').format('YYYY-MM-DD') : '';
      return { key: k(), label: labels[i], amount: String(amt), due_date: due };
    });
  }

  // ---------- Live conflict check ----------
  const payloadEvents = events.map(({ key, ...e }) => ({ ...e, start_time: e.start_time || null, end_time: e.end_time || null }));
  const payloadServices = services.map(serviceToApi);
  const conflictKey = useDebounce(JSON.stringify([payloadEvents.map((e) => [e.event_date, e.start_time, e.end_time, e.team.map((t) => t.member_id)]), payloadServices.map((s) => [s.on_site, s.team])]), 500);
  useEffect(() => {
    if (!ready || !can('bookings.edit')) return;
    const anyTeam = payloadEvents.some((e) => e.team.length) || payloadServices.some((s) => s.on_site && s.team.length);
    if (!anyTeam || !payloadEvents.some((e) => e.event_date)) return setConflicts([]);
    let off = false;
    api
      .post<any[]>('bookings/conflicts', { events: payloadEvents, services: payloadServices, booking_id: id ? Number(id) : 0 })
      .then((r) => !off && setConflicts(r))
      .catch(() => undefined);
    return () => {
      off = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conflictKey, ready]);

  const busyOn = (idx: number, mid: number) => conflicts.filter((c) => c.index === idx && c.member_id === mid);
  const svcConflicts = conflicts.filter((c) => (c.services || []).length > 0);

  // ---------- Mutators ----------
  const setEv = (key: string, patch: Partial<Ev>) => setEvents((l) => l.map((e) => (e.key === key ? { ...e, ...patch } : e)));
  const addTeam = (key: string, mid: number) => {
    const m = crew.find((x) => x.id === mid);
    if (!m) return;
    setEvents((l) => l.map((e) => (e.key === key && !e.team.some((t) => t.member_id === mid) ? { ...e, team: [...e.team, { member_id: mid, role: defaultRole(m.role) }] } : e)));
  };
  const setInstRow = (key: string, patch: Partial<Inst>) => setInst((l) => l.map((i) => (i.key === key ? { ...i, ...patch } : i)));

  const total = num(h.total_amount);
  const showMoney = !isEdit || !!existing.data?.payment;
  const scheduled = inst.reduce((s, i) => s + num(i.amount), 0);

  // ---------- Save ----------
  const submit = async (force = false) => {
    const e: Record<string, string> = {};
    if (!clientId && !newClient) e.client = 'Choose a client or add a new one.';
    if (newClient) {
      if (!newClient.name.trim()) e.client = 'Client name is required.';
      else if (newClient.phone.replace(/\D/g, '').length < 10) e.client = 'Enter a valid client phone number.';
    }
    if (!h.title.trim()) e.title = 'Booking title is required.';
    if (showMoney && (h.total_amount === '' || total < 0)) e.total_amount = 'Enter the total package amount.';
    events.forEach((ev, i) => {
      if (!ev.name.trim()) e.events = `Event ${i + 1} needs a name.`;
      else if (!ev.event_date) e.events = `Event ${i + 1} needs a date.`;
      else if (ev.start_time && ev.end_time && ev.end_time <= ev.start_time && ev.end_time !== '00:00') e.events = `Event ${i + 1}: end time must be after start time.`;
    });
    if (!events.length) e.events = 'Add at least one event.';
    inst.forEach((i, n) => {
      if (!i.label.trim() || num(i.amount) <= 0) e.inst = `Installment ${n + 1} needs a label and an amount.`;
    });
    if (scheduled - total > 0.009) e.inst = 'Installments add up to more than the package amount.';
    if (!isEdit && num(adv.amount) > total) e.adv = 'Advance cannot be more than the package amount.';
    if (!isEdit && num(adv.amount) > 0 && adv.paid_on > today()) e.adv = 'Payment date cannot be in the future.';
    const se = validateServices(services);
    setSvcErrors(se);
    if (Object.keys(se).length) e.services = 'Every service needs a name.';
    if (canDl && dls.some((d) => !d.name.trim())) e.dls = 'Every deliverable needs a name.';
    setErrors(e);
    if (Object.values(e).some(Boolean)) return toast(Object.values(e).find(Boolean)!, 'error');

    const body: any = {
      ...h,
      total_amount: total,
      delivery_due: h.delivery_due || null,
      lead_id: lead,
      quotation_id: quotationId,
      events: payloadEvents,
      installments: inst.map(({ key, ...i }) => ({ ...i, amount: num(i.amount), due_date: i.due_date || null })),
      services: payloadServices,
      force,
    };
    if (canDl && dls.length) body.deliverables = dls.map((d) => ({ name: d.name.trim(), description: d.description, assigned_to: d.assigned_to ? Number(d.assigned_to) : null, expected_date: dlDate(d) || null }));
    if (clientId) body.client_id = clientId;
    else body.client = newClient;
    if (!isEdit && num(adv.amount) > 0) body.advance = { ...adv, amount: num(adv.amount) };
    // Members who can't see money must not wipe the payment schedule.
    if (isEdit && !existing.data?.payment) {
      delete body.installments;
      delete body.total_amount;
    }

    setSaving(true);
    try {
      const r = isEdit ? await api.put(`bookings/${id}`, body) : await api.post('bookings', body);
      ['bookings', 'booking', 'leads', 'lead', 'pipeline', 'dashboard', 'calendar', 'payments', 'quotations', 'quotation', 'clients', 'tasks'].forEach((x) => qc.invalidateQueries({ queryKey: [x] }));
      toast(isEdit ? 'Booking updated' : `Booking ${r.number} confirmed`);
      nav(`/bookings/${r.id}`, { replace: true, state: r.portal_pin_once ? { portal_pin_once: r.portal_pin_once } : undefined });
    } catch (err) {
      if (err instanceof ApiError && err.status === 409 && err.data?.conflicts) {
        setConflicts(err.data.conflicts);
        setSaving(false);
        const lines = Array.from(new Set<string>(err.data.conflicts.map((c: any) => `${c.member_name}: ${c.event_name}, ${fmtDate(c.event_date)} (${c.booking_number})`)));
        if (await confirm({ title: 'Team member already booked', message: <><ul className="mb-2 list-disc pl-4">{lines.map((l) => <li key={l}>{l}</li>)}</ul>Save anyway? Only an admin or production manager can override this.</>, confirmLabel: 'Save anyway', danger: true })) submit(true);
        return;
      }
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      toast((err as Error).message, 'error');
    }
    setSaving(false);
  };

  if (existing.error || preview.error || quote.error) return <ErrorState error={existing.error || preview.error || quote.error} />;
  if (!ready) return <PageLoader />;

  return (
    <div className="pb-24 lg:pb-0">
      <PageHeader
        back={<Link to={isEdit ? `/bookings/${id}` : '/bookings'} className="mb-1 inline-flex items-center gap-1 text-xs text-muted hover:text-ink"><ArrowLeft className="h-3.5 w-3.5" /> {isEdit ? 'Back to booking' : 'Bookings'}</Link>}
        title={isEdit ? `Edit ${existing.data?.number}` : 'New booking'}
        subtitle={isEdit ? 'Changes to events and team are checked for double bookings.' : lead ? 'Pre-filled from the lead and its latest quotation. Check the details and confirm.' : 'Add the client, event dates, team and payment schedule.'}
      />
      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        <div className="min-w-0 space-y-5">
          <Card className="p-4 sm:p-5">
            <h3 className="mb-3 text-sm font-semibold">Client</h3>
            <ClientPicker
              clientId={clientId}
              label={clientLabel}
              newClient={newClient}
              locked={isEdit}
              error={errors.client || errors.client_id}
              onPick={(c) => (setClientId(c.id), setClientLabel(`${c.name} · ${phoneDisplay(c.phone)}`), setNewClient(null), !h.title && setH((x) => ({ ...x, title: c.name })))}
              onNew={(c) => (setClientId(null), setNewClient(c))}
            />
          </Card>

          <Card className="p-4 sm:p-5">
            <h3 className="mb-3 text-sm font-semibold">Package</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Booking title" required error={errors.title} className="sm:col-span-2">
                <Input value={h.title} onChange={(e) => setH({ ...h, title: e.target.value })} placeholder="Ananya & Rohan – Wedding" invalid={!!errors.title} />
              </Field>
              <Field label="Event type">
                <Select value={h.event_type} onChange={(e) => setH({ ...h, event_type: e.target.value })} options={config.event_types} placeholder="Select…" />
              </Field>
              <Field label="Total package amount (₹)" required error={errors.total_amount}>
                <Input value={h.total_amount} onChange={(e) => setH({ ...h, total_amount: e.target.value })} inputMode="decimal" placeholder="350000" invalid={!!errors.total_amount} />
              </Field>
              <Field label="Booking confirmed on">
                <Input type="date" value={h.confirmed_on} onChange={(e) => setH({ ...h, confirmed_on: e.target.value })} />
              </Field>
              <Field label="Delivery due date">
                <Input type="date" value={h.delivery_due} onChange={(e) => setH({ ...h, delivery_due: e.target.value })} />
              </Field>
              <Field label="Package details" className="sm:col-span-2">
                <Textarea rows={3} value={h.package_details} onChange={(e) => setH({ ...h, package_details: e.target.value })} placeholder="Candid photography × 2 days, cinematic film, 40-page album…" />
              </Field>
              <Field label="Special instructions" className="sm:col-span-2">
                <Textarea rows={2} value={h.special_instructions} onChange={(e) => setH({ ...h, special_instructions: e.target.value })} placeholder="Family rituals, must-have shots, contact person at the venue…" />
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Events & team" subtitle="Add each function (haldi, sangeet, wedding…) with its own date, venue and crew." actions={<Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEvents((l) => [...l, newEvent({ event_type: h.event_type, event_date: l[l.length - 1]?.event_date || '', city: l[l.length - 1]?.city || '' })])}>Add event</Button>} />
            {errors.events && <p className="mx-4 mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{errors.events}</p>}
            <div className="space-y-3 px-4 pb-4">
              {events.map((ev, idx) => {
                const evConf = conflicts.filter((c) => c.index === idx);
                return (
                  <div key={ev.key} className={clsx('rounded-xl border p-3 sm:p-4', evConf.length ? 'border-amber-300 dark:border-amber-500/40' : 'border-line')}>
                    <div className="mb-3 flex items-center gap-2">
                      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-subtle text-xs font-semibold">{idx + 1}</span>
                      <Input value={ev.name} onChange={(e) => setEv(ev.key, { name: e.target.value })} placeholder="Event name, e.g. Sangeet" className="flex-1 font-medium" />
                      {events.length > 1 && (
                        <button className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" onClick={() => setEvents((l) => l.filter((x) => x.key !== ev.key))} aria-label="Remove event"><Trash2 className="h-4 w-4" /></button>
                      )}
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                      <Field label="Date" required><Input type="date" value={ev.event_date} onChange={(e) => setEv(ev.key, { event_date: e.target.value })} /></Field>
                      <Field label="Start"><Input type="time" value={ev.start_time} onChange={(e) => setEv(ev.key, { start_time: e.target.value })} /></Field>
                      <Field label="End"><Input type="time" value={ev.end_time} onChange={(e) => setEv(ev.key, { end_time: e.target.value })} /></Field>
                      <Field label="Type"><Select value={ev.event_type} onChange={(e) => setEv(ev.key, { event_type: e.target.value })} options={config.event_types} placeholder="—" /></Field>
                      <Field label="Venue" className="col-span-2"><Input value={ev.venue} onChange={(e) => setEv(ev.key, { venue: e.target.value })} placeholder="ITC Grand Chola" /></Field>
                      <Field label="City" className="col-span-2"><Input value={ev.city} onChange={(e) => setEv(ev.key, { city: e.target.value })} placeholder="Chennai" /></Field>
                    </div>
                    <div className="mt-3">
                      <p className="label">Team</p>
                      <div className="flex flex-wrap items-center gap-2">
                        {ev.team.map((t) => {
                          const m = crew.find((x) => x.id === t.member_id) || (team.data || []).find((x) => x.id === t.member_id);
                          const busy = busyOn(idx, t.member_id);
                          return (
                            <div key={t.member_id} className={clsx('flex items-center gap-1.5 rounded-lg border py-1 pl-1.5 pr-1', busy.length ? 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' : 'border-line bg-subtle/50')} title={busy.length ? `Also on ${busy[0].booking_title} (${busy[0].event_name})` : undefined}>
                              <Avatar name={m?.name} color={m?.color} size="xs" />
                              <span className="text-xs font-medium">{m?.name || 'Member'}</span>
                              {busy.length > 0 && <AlertTriangle className="h-3.5 w-3.5 text-amber-600" />}
                              <select value={t.role} onChange={(e) => setEv(ev.key, { team: ev.team.map((x) => (x.member_id === t.member_id ? { ...x, role: e.target.value } : x)) })} className="rounded border-0 bg-transparent py-0 pl-1 pr-6 text-xs text-muted focus:ring-0" aria-label="Role">
                                {Object.entries(config.event_roles).map(([r, l]) => <option key={r} value={r}>{l}</option>)}
                              </select>
                              <button onClick={() => setEv(ev.key, { team: ev.team.filter((x) => x.member_id !== t.member_id) })} className="rounded p-0.5 text-faint hover:text-ink" aria-label="Remove"><X className="h-3.5 w-3.5" /></button>
                            </div>
                          );
                        })}
                        <label className="relative inline-flex items-center">
                          <UserPlus className="pointer-events-none absolute left-2 h-3.5 w-3.5 text-accent-ink" />
                          <select value="" onChange={(e) => e.target.value && addTeam(ev.key, Number(e.target.value))} className="input w-auto py-1 pl-7 text-xs">
                            <option value="">Assign…</option>
                            {crew.filter((m) => !ev.team.some((t) => t.member_id === m.id)).map((m) => <option key={m.id} value={m.id}>{m.name} · {config.roles[m.role]}</option>)}
                          </select>
                        </label>
                      </div>
                      {evConf.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-xs text-amber-700 dark:text-amber-300">
                          {evConf.map((c, i) => (
                            <li key={i}>⚠ {c.member_name} is already on <Link to={`/bookings/${c.booking_id}`} className="underline">{c.booking_title}</Link> – {c.event_name}, {fmtDate(c.event_date)}{c.start_time ? ` ${fmtTime(c.start_time)}–${fmtTime(c.end_time)}` : ''}</li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </Card>

          <Card>
            <CardHeader title="Services & team" subtitle="What you deliver on this booking and who does it. On-site services block the member on every event date." />
            <div className="px-4 pb-4">
              {errors.services && <p className="mb-2 text-xs text-red-600">{errors.services}</p>}
              <ServicesEditor value={services} onChange={setServices} dates={events.map((e) => e.event_date)} bookingId={id ? Number(id) : undefined} errors={svcErrors} />
              {svcConflicts.length > 0 && (
                <ul className="mt-3 space-y-0.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                  {Array.from(new Map(svcConflicts.map((c) => [`${c.member_id}-${c.event_id}`, c])).values()).map((c: any) => (
                    <li key={`${c.member_id}-${c.event_id}`}>⚠ {c.member_name} is already on <Link to={`/bookings/${c.booking_id}`} className="underline">{c.booking_title}</Link> – {c.event_name}, {fmtDate(c.event_date)}{c.start_time ? ` ${fmtTime(c.start_time)}` : ''}</li>
                  ))}
                </ul>
              )}
            </div>
          </Card>

          {canDl && (
            <Card>
              <CardHeader
                title="Deliverables"
                subtitle="What the client receives. Pick a template, then edit before saving. These show on the client portal."
                actions={
                  <div className="flex items-center gap-2">
                    <label className="relative inline-flex items-center">
                      <LayoutTemplate className="pointer-events-none absolute left-2 h-3.5 w-3.5 text-accent-ink" />
                      <select value={tplId} onChange={(e) => applyTemplate(e.target.value)} className="input w-auto py-1 pl-7 text-xs" aria-label="Use template">
                        <option value="">Use template…</option>
                        {(templates.data || []).map((t: any) => <option key={t.id} value={t.id}>{t.name} ({t.items.length})</option>)}
                      </select>
                    </label>
                    <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setDls((l) => [...l, { key: k(), name: '', description: '', assigned_to: '', expected_date: '', days: null, touched: true }])}>Add</Button>
                  </div>
                }
              />
              <div className="space-y-2 px-4 pb-4">
                {errors.dls && <p className="text-xs text-red-600">{errors.dls}</p>}
                {dls.length === 0 && <p className="flex items-center gap-2 text-sm text-muted"><PackageCheck className="h-4 w-4" /> No deliverables yet. You can also add them later from the booking.</p>}
                {dls.map((d) => (
                  <div key={d.key} className="grid gap-2 rounded-lg border border-line p-2 sm:grid-cols-[1fr_160px_150px_auto] sm:items-center">
                    <Input value={d.name} onChange={(e) => setDl(d.key, { name: e.target.value })} placeholder="Deliverable name" className="py-1.5 text-sm" aria-label="Deliverable name" />
                    <select value={d.assigned_to} onChange={(e) => setDl(d.key, { assigned_to: e.target.value })} className="input py-1.5 text-xs" aria-label="Assigned to">
                      <option value="">Not assigned</option>
                      {crew.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                    <Input type="date" value={dlDate(d)} onChange={(e) => setDl(d.key, { expected_date: e.target.value, touched: true })} className="py-1.5 text-xs" aria-label="Expected date" title={d.days !== null && !d.touched ? `${d.days} days after the last event` : undefined} />
                    <button onClick={() => setDls((l) => l.filter((x) => x.key !== d.key))} className="justify-self-end rounded p-1.5 text-faint hover:text-red-600" aria-label="Remove deliverable"><Trash2 className="h-4 w-4" /></button>
                  </div>
                ))}
              </div>
            </Card>
          )}
        </div>

        <div className="space-y-5 lg:sticky lg:top-20 lg:self-start">
          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
              <h3 className="text-sm font-semibold">Payment schedule</h3>
              <div className="flex gap-1">
                {[[30, 40, 30], [50, 50], [100]].map((p) => (
                  <button key={p.join()} onClick={() => setInst(split(total, p))} disabled={!total} className="rounded border border-line px-1.5 py-0.5 text-2xs text-muted hover:bg-subtle hover:text-ink disabled:opacity-40">{p.join('/')}</button>
                ))}
              </div>
            </div>
            {errors.inst && <p className="mb-2 text-xs text-red-600">{errors.inst}</p>}
            <div className="space-y-2">
              {inst.map((i) => (
                <div key={i.key} className="rounded-lg border border-line p-2">
                  <div className="flex items-center gap-1.5">
                    <Input value={i.label} onChange={(e) => setInstRow(i.key, { label: e.target.value })} className="flex-1 py-1 text-xs" aria-label="Label" />
                    <button onClick={() => setInst((l) => l.filter((x) => x.key !== i.key))} className="rounded p-1 text-faint hover:text-red-600" aria-label="Remove installment"><X className="h-3.5 w-3.5" /></button>
                  </div>
                  <div className="mt-1.5 flex gap-1.5">
                    <Input value={i.amount} onChange={(e) => setInstRow(i.key, { amount: e.target.value })} inputMode="decimal" className="w-1/2 py-1 text-right text-xs" aria-label="Amount" placeholder="Amount" />
                    <Input type="date" value={i.due_date} onChange={(e) => setInstRow(i.key, { due_date: e.target.value })} className="w-1/2 py-1 text-xs" aria-label="Due date" />
                  </div>
                </div>
              ))}
              <Button size="sm" variant="ghost" icon={<Plus className="h-4 w-4" />} onClick={() => setInst((l) => [...l, { key: k(), label: `Installment ${l.length + 1}`, amount: String(Math.max(0, total - scheduled) || ''), due_date: '' }])}>Add installment</Button>
            </div>
            <div className="mt-3 space-y-1 border-t border-line pt-3 text-xs">
              <div className="flex justify-between"><span className="text-muted">Package</span><span className="tabular">{inr(total)}</span></div>
              <div className="flex justify-between"><span className="text-muted">Scheduled</span><span className={clsx('tabular', scheduled - total > 0.009 && 'font-medium text-red-600')}>{inr(scheduled)}</span></div>
              {total - scheduled > 0.009 && <div className="flex justify-between text-amber-700 dark:text-amber-300"><span>Not scheduled yet</span><span className="tabular">{inr(total - scheduled)}</span></div>}
            </div>
          </Card>

          {!isEdit && can('payments.edit') && (
            <Card className="p-4">
              <h3 className="mb-1 text-sm font-semibold">Advance received</h3>
              <p className="mb-3 text-xs text-muted">Optional. Records the first payment and issues a receipt.</p>
              {errors.adv && <p className="mb-2 text-xs text-red-600">{errors.adv}</p>}
              <div className="grid grid-cols-2 gap-2">
                <Field label="Amount (₹)" className="col-span-2"><Input value={adv.amount} onChange={(e) => setAdv({ ...adv, amount: e.target.value })} inputMode="decimal" placeholder="0" /></Field>
                <Field label="Date"><Input type="date" value={adv.paid_on} max={today()} onChange={(e) => setAdv({ ...adv, paid_on: e.target.value })} /></Field>
                <Field label="Method"><Select value={adv.method} onChange={(e) => setAdv({ ...adv, method: e.target.value })} options={config.payment_methods} /></Field>
                <Field label="Transaction ID" className="col-span-2"><Input value={adv.transaction_ref} onChange={(e) => setAdv({ ...adv, transaction_ref: e.target.value })} placeholder="UPI ref / cheque no." /></Field>
              </div>
            </Card>
          )}

          <Button variant="primary" className="hidden w-full lg:flex" loading={saving} onClick={() => submit()}>{isEdit ? 'Save changes' : 'Confirm booking'}</Button>
        </div>
      </div>
      <div className="fixed inset-x-0 bottom-14 z-30 flex items-center gap-3 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:hidden">
        <div className="min-w-0 flex-1"><p className="text-2xs text-muted">Package</p><p className="tabular font-semibold">{inr(total)}</p></div>
        <Button variant="primary" loading={saving} onClick={() => submit()}>{isEdit ? 'Save' : 'Confirm booking'}</Button>
      </div>
    </div>
  );
}

function ClientPicker({ clientId, label, newClient, locked, error, onPick, onNew }: { clientId: number | null; label: string; newClient: Client | null; locked: boolean; error?: string; onPick: (c: any) => void; onNew: (c: Client | null) => void }) {
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 250);
  const res = useQuery({ queryKey: ['clients', 'pick', dq], queryFn: () => api.get('clients', { q: dq, per_page: 8 }), enabled: !clientId && !newClient && dq.length > 1 });
  if (clientId)
    return (
      <div className="flex items-center gap-3 rounded-lg border border-line bg-subtle/50 px-3 py-2.5">
        <Avatar name={label} size="sm" />
        <Link to={`/clients/${clientId}`} className="flex-1 text-sm font-medium hover:text-accent-ink">{label}</Link>
        {!locked && <Button size="xs" variant="ghost" onClick={() => onNew(null)}>Change</Button>}
      </div>
    );
  if (newClient)
    return (
      <div>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Client name" required><Input value={newClient.name} onChange={(e) => onNew({ ...newClient, name: e.target.value })} invalid={!!error} /></Field>
          <Field label="Phone" required><Input value={newClient.phone} onChange={(e) => onNew({ ...newClient, phone: e.target.value })} inputMode="tel" invalid={!!error} /></Field>
          <Field label="Email"><Input type="email" value={newClient.email} onChange={(e) => onNew({ ...newClient, email: e.target.value })} /></Field>
          <Field label="City"><Input value={newClient.city} onChange={(e) => onNew({ ...newClient, city: e.target.value })} /></Field>
        </div>
        {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
        <button className="mt-2 text-xs text-muted hover:text-ink" onClick={() => onNew(null)}>Pick an existing client instead</button>
      </div>
    );
  const items = res.data?.items || [];
  return (
    <div>
      <SearchInput value={q} onChange={setQ} placeholder="Search existing clients by name or phone…" />
      {dq.length > 1 && (
        <ul className="mt-2 divide-y divide-line rounded-lg border border-line">
          {res.isLoading ? <li className="px-3 py-2 text-sm text-muted">Searching…</li> : items.length === 0 ? <li className="px-3 py-2 text-sm text-muted">No client found.</li> : items.map((c: any) => (
            <li key={c.id}><button className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-subtle" onClick={() => onPick(c)}><span className="font-medium">{c.name}</span><span className="text-xs text-muted">{phoneDisplay(c.phone)}{c.city ? ' · ' + c.city : ''}</span></button></li>
          ))}
        </ul>
      )}
      {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
      <Button size="sm" variant="ghost" className="mt-2" icon={<Plus className="h-4 w-4" />} onClick={() => onNew({ name: q && !/\d/.test(q) ? q : '', phone: /\d/.test(q) ? q : '', email: '', city: '' })}>New client</Button>
    </div>
  );
}
