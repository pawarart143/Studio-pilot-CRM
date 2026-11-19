import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, ImagePlus, Pencil, Plus, Trash2, X } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useForm, validate } from '@/lib/form';
import { useUrlState } from '@/lib/hooks';
import { fmtDate, inr } from '@/lib/format';
import { Badge, Button, Card, CardHeader, Checkbox, EmptyState, ErrorState, Field, Input, Modal, PageHeader, SearchInput, Select, TableSkeleton, Tabs, Textarea, Toggle, useUi } from '@/components/ui';
import { PortalCard, portalStatus } from '@/components/delivery';
import { Link } from 'react-router-dom';
import EnquirySettings from '@/pages/enquiry/EnquirySettings';

export default function Settings() {
  const { can, member } = useAuth();
  const admin = can('settings.edit');
  const [f, setF] = useUrlState({ tab: admin ? 'studio' : 'profile' });
  const tabs: [string, string][] = admin
    ? [['studio', 'Studio'], ['business', 'Quotes & invoices'], ['services', 'Services & prices'], ['deliverables', 'Deliverable templates'], ...(can('portal.manage') ? ([['portal', 'Client portal']] as [string, string][]) : []), ...(can('enquiry.manage') ? ([['enquiry', 'Enquiry form']] as [string, string][]) : []), ['whatsapp', 'WhatsApp'], ['payments', 'Online payments'], ['profile', 'My profile'], ['plan', 'Plan'], ['data', 'Data']]
    : [['profile', 'My profile']];
  const tab = tabs.some(([k]) => k === f.tab) ? f.tab : tabs[0][0];
  return (
    <div>
      <PageHeader title="Settings" subtitle={admin ? 'Studio profile, pricing, templates and your account.' : 'Your account.'} />
      <Card>
        <div className="px-4 pt-1"><Tabs value={tab} onChange={(t) => setF({ tab: t })} tabs={tabs} /></div>
        <div className="p-4 sm:p-6">
          {tab === 'studio' && <StudioTab />}
          {tab === 'business' && <BusinessTab />}
          {tab === 'services' && <ServicesTab />}
          {tab === 'deliverables' && <TemplatesTab />}
          {tab === 'portal' && <PortalsTab />}
          {tab === 'enquiry' && <EnquirySettings />}
          {tab === 'whatsapp' && <WhatsAppTab />}
          {tab === 'payments' && <RazorpayTab />}
          {tab === 'profile' && <ProfileTab key={member.id} />}
          {tab === 'plan' && <PlanTab />}
          {tab === 'data' && <DataTab />}
        </div>
      </Card>
    </div>
  );
}

function useSaveSettings() {
  const qc = useQueryClient();
  const { toast } = useUi();
  return async (body: any, f?: { fromError: (e: unknown) => void }) => {
    try {
      const me = await api.put('settings', body);
      qc.setQueryData(['me'], me);
      toast('Settings saved');
      return true;
    } catch (e) {
      f?.fromError(e);
      toast((e as Error).message, 'error');
      return false;
    }
  };
}

/* ---------- Client portals (owner/admin) ---------- */
function PortalsTab() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [open, setOpen] = useState<any>(null);
  const q = useQuery({ queryKey: ['portals', search, status], queryFn: () => api.get<any[]>('portals', { search, status }) });
  const rows = q.data || [];
  return (
    <div>
      <p className="mb-4 max-w-3xl text-sm text-muted">Every booking has a private client portal. It becomes <b className="text-ink">Active</b> when you generate a PIN. Share the link and PIN with the client on WhatsApp. Only owners and admins can see PINs.</p>
      <div className="mb-3 flex flex-wrap gap-2">
        <SearchInput value={search} onChange={setSearch} placeholder="Search booking, client or phone" className="w-full sm:w-72" />
        <Select value={status} onChange={(e) => setStatus(e.target.value)} options={Object.fromEntries(Object.entries(portalStatus).map(([k, v]) => [k, v.label]))} placeholder="All statuses" className="w-full sm:w-44" />
      </div>
      {q.isLoading ? (
        <TableSkeleton rows={5} cols={4} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : !rows.length ? (
        <EmptyState title="No portals found" description={search || status ? 'Try a different search or status.' : 'Portals appear here as soon as you create bookings.'} />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-subtle/60 text-left text-xs text-muted">
              <tr><th className="px-3 py-2 font-medium">Booking</th><th className="px-3 py-2 font-medium">Client</th><th className="px-3 py-2 font-medium">Event</th><th className="px-3 py-2 font-medium">Portal</th><th className="px-3 py-2 font-medium">Views</th><th className="px-3 py-2" /></tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((r) => (
                <tr key={r.booking_id} className="hover:bg-subtle/40">
                  <td className="px-3 py-2"><Link to={`/bookings/${r.booking_id}`} className="font-medium hover:text-accent-ink">{r.number}</Link><div className="max-w-[220px] truncate text-xs text-muted">{r.title}</div></td>
                  <td className="px-3 py-2">{r.client_name}</td>
                  <td className="px-3 py-2 text-muted">{r.first_date ? fmtDate(r.first_date) : '—'}</td>
                  <td className="px-3 py-2"><Badge tone={portalStatus[r.status].tone}>{portalStatus[r.status].label}</Badge></td>
                  <td className="px-3 py-2 tabular text-muted">{r.view_count}</td>
                  <td className="px-3 py-2 text-right"><Button size="xs" variant={r.status === 'not_activated' ? 'primary' : 'secondary'} onClick={() => setOpen(r)}>{r.status === 'not_activated' ? 'Generate PIN' : 'Manage'}</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Modal open={!!open} onClose={() => (setOpen(null), q.refetch())} title={open ? `${open.number} · ${open.client_name}` : ''} subtitle={open?.title}>
        {open && <PortalCard booking={{ id: open.booking_id }} compact />}
      </Modal>
    </div>
  );
}

/* ---------- Studio profile ---------- */
function StudioTab() {
  const { studio } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const save = useSaveSettings();
  const f = useForm({ name: studio.name || '', email: studio.email || '', phone: studio.phone || '', address: studio.address || '', city: studio.city || '', state: studio.state || '', gstin: studio.gstin || '' });
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const file = useRef<HTMLInputElement>(null);
  const submit = async () => {
    const e = validate(f.values, { name: ['required'], email: ['email'], phone: ['phone'] }, { name: 'Studio name' });
    if (f.values.gstin && !/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i.test(f.values.gstin)) e.gstin = 'GSTIN should be 15 characters, e.g. 27ABCDE1234F1Z5.';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    await save(f.values, f);
    setBusy(false);
  };
  const upload = async (fl: File) => {
    if (fl.size > 2 * 1024 * 1024) return toast('Logo must be 2 MB or smaller.', 'error');
    const fd = new FormData();
    fd.append('file', fl);
    setUploading(true);
    try {
      await api.upload('settings/logo', fd);
      await qc.invalidateQueries({ queryKey: ['me'] });
      toast('Logo updated');
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setUploading(false);
  };
  return (
    <div className="max-w-2xl space-y-6">
      <div className="flex items-center gap-4">
        <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-line bg-subtle">
          {studio.logo_url ? <img src={studio.logo_url} alt="Studio logo" className="h-full w-full object-contain" /> : <ImagePlus className="h-6 w-6 text-faint" />}
        </div>
        <div>
          <Button size="sm" loading={uploading} onClick={() => file.current?.click()}>{studio.logo_url ? 'Change logo' : 'Upload logo'}</Button>
          <p className="mt-1 text-xs text-muted">PNG, JPG or WebP, up to 2 MB. Shown on quotations and receipts.</p>
          <input ref={file} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Studio name" required error={f.errors.name} className="sm:col-span-2"><Input {...f.bind('name')} /></Field>
        <Field label="Phone" error={f.errors.phone}><Input {...f.bind('phone')} inputMode="tel" /></Field>
        <Field label="Email" error={f.errors.email}><Input type="email" {...f.bind('email')} /></Field>
        <Field label="Address" className="sm:col-span-2"><Textarea rows={2} {...f.bind('address')} /></Field>
        <Field label="City"><Input {...f.bind('city')} /></Field>
        <Field label="State"><Input {...f.bind('state')} placeholder="Maharashtra" /></Field>
        <Field label="GSTIN" error={f.errors.gstin} hint="Printed on quotations and receipts."><Input {...f.bind('gstin')} className="uppercase" maxLength={15} /></Field>
      </div>
      <Button variant="primary" loading={busy} onClick={submit}>Save studio profile</Button>
    </div>
  );
}

/* ---------- Business settings ---------- */
function BusinessTab() {
  const { studio } = useAuth();
  const s = studio.settings || {};
  const save = useSaveSettings();
  const f = useForm({
    gst_enabled: s.gst_enabled !== false,
    gst_rate: String(s.gst_rate ?? 18),
    quote_prefix: s.quote_prefix || 'QT',
    booking_prefix: s.booking_prefix || 'BK',
    receipt_prefix: s.receipt_prefix || 'RC',
    quote_validity: String(s.quote_validity ?? 15),
    quote_terms: s.quote_terms || '',
    bank_details: s.bank_details || '',
    upi_id: s.upi_id || '',
  });
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    const v = f.values;
    const e: Record<string, string> = {};
    if (isNaN(Number(v.gst_rate)) || Number(v.gst_rate) < 0 || Number(v.gst_rate) > 28) e.gst_rate = 'Between 0 and 28.';
    if (!/^\d+$/.test(v.quote_validity) || Number(v.quote_validity) < 1) e.quote_validity = 'Number of days.';
    if (v.upi_id && !/^[\w.-]+@[\w.-]+$/.test(v.upi_id)) e.upi_id = 'Looks like name@bank.';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    await save({ settings: { ...v, gst_rate: Number(v.gst_rate), quote_validity: Number(v.quote_validity) } }, f);
    setBusy(false);
  };
  return (
    <div className="max-w-2xl space-y-6">
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">GST</h3>
        <Toggle checked={f.values.gst_enabled} onChange={(v) => f.set('gst_enabled', v)} label="Add GST to new quotations by default" />
        {f.values.gst_enabled && <Field label="Default GST rate (%)" error={f.errors.gst_rate} hint="Split as CGST + SGST on documents." className="max-w-[200px]"><Input {...f.bind('gst_rate')} inputMode="decimal" /></Field>}
      </section>
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Numbering</h3>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Quotation prefix" hint={`${f.values.quote_prefix || 'QT'}-2026-0001`}><Input {...f.bind('quote_prefix')} className="uppercase" maxLength={10} /></Field>
          <Field label="Booking prefix" hint={`${f.values.booking_prefix || 'BK'}-2026-0001`}><Input {...f.bind('booking_prefix')} className="uppercase" maxLength={10} /></Field>
          <Field label="Receipt prefix" hint={`${f.values.receipt_prefix || 'RC'}-2026-0001`}><Input {...f.bind('receipt_prefix')} className="uppercase" maxLength={10} /></Field>
        </div>
      </section>
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Quotations</h3>
        <Field label="Valid for (days)" error={f.errors.quote_validity} className="max-w-[200px]"><Input {...f.bind('quote_validity')} inputMode="numeric" /></Field>
        <Field label="Default terms & conditions"><Textarea rows={5} {...f.bind('quote_terms')} /></Field>
      </section>
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Payment details (printed on quotations and receipts)</h3>
        <Field label="UPI ID" error={f.errors.upi_id}><Input {...f.bind('upi_id')} placeholder="studio@okhdfcbank" /></Field>
        <Field label="Bank details"><Textarea rows={3} {...f.bind('bank_details')} placeholder={'A/C name: …\nA/C no: …\nIFSC: …'} /></Field>
      </section>
      <Button variant="primary" loading={busy} onClick={submit}>Save</Button>
    </div>
  );
}

/* ---------- Services catalogue ---------- */
function TemplatesTab() {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['deliverable-templates', 'all'], queryFn: () => api.get<any[]>('deliverable-templates') });
  const [edit, setEdit] = useState<any>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['deliverable-templates'] });
  const del = async (t: any) => {
    if (!(await confirm({ title: `Delete template "${t.name}"?`, message: 'Bookings that already used it keep their deliverables.', danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`deliverable-templates/${t.id}`).then(refresh, (e) => toast(e.message, 'error'));
  };
  const toggle = (t: any) => api.put(`deliverable-templates/${t.id}`, { is_active: !t.is_active }).then(refresh, (e) => toast(e.message, 'error'));
  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <p className="text-sm text-muted">Pick one of these when you create a booking, then edit the list before saving. Expected dates count from the last event date.</p>
        <Button variant="primary" size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>New template</Button>
      </div>
      {!q.data?.length ? (
        <EmptyState title="No templates yet" description="Create a template like Wedding Package with its photos, film and album." />
      ) : (
        <ul className="space-y-3">
          {q.data.map((t) => (
            <li key={t.id} className={clsx('rounded-xl border border-line p-4', !t.is_active && 'opacity-60')}>
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{t.name} {t.event_type && <Badge className="ml-1">{config.event_types[t.event_type]}</Badge>}</p>
                  {t.description && <p className="text-xs text-muted">{t.description}</p>}
                </div>
                <Toggle checked={!!t.is_active} onChange={() => toggle(t)} label={<span className="text-xs">Active</span>} />
                <button onClick={() => setEdit(t)} className="rounded p-1.5 text-faint hover:text-ink" aria-label="Edit"><Pencil className="h-4 w-4" /></button>
                <button onClick={() => del(t)} className="rounded p-1.5 text-faint hover:text-red-600" aria-label="Delete"><Trash2 className="h-4 w-4" /></button>
              </div>
              <ol className="mt-2 grid gap-x-6 gap-y-0.5 text-sm sm:grid-cols-2">
                {t.items.map((i: any) => <li key={i.id} className="flex justify-between gap-2"><span>{i.name}</span>{i.days_after_event !== null && <span className="text-xs text-muted">+{i.days_after_event} days</span>}</li>)}
              </ol>
            </li>
          ))}
        </ul>
      )}
      <DlTemplateModal template={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </div>
  );
}

type TplItem = { name: string; description: string; days: string };
function DlTemplateModal({ template, onClose, onSaved }: { template: any; onClose: () => void; onSaved: () => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const [h, setH] = useState({ name: '', event_type: '', description: '' });
  const [items, setItems] = useState<TplItem[]>([]);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!template) return;
    setH({ name: template.name || '', event_type: template.event_type || '', description: template.description || '' });
    setItems(template.items?.length ? template.items.map((i: any) => ({ name: i.name, description: i.description || '', days: i.days_after_event === null || i.days_after_event === undefined ? '' : String(i.days_after_event) })) : [{ name: '', description: '', days: '' }]);
    setErr('');
  }, [template]);
  const setItem = (i: number, patch: Partial<TplItem>) => setItems((l) => l.map((x, j) => (j === i ? { ...x, ...patch } : x)));
  const move = (i: number, d: number) => setItems((l) => { const n = [...l]; const [x] = n.splice(i, 1); n.splice(i + d, 0, x); return n; });
  const save = async () => {
    if (!h.name.trim()) return setErr('Give the template a name.');
    const list = items.filter((i) => i.name.trim());
    if (!list.length) return setErr('Add at least one deliverable.');
    if (list.some((i) => i.days !== '' && !/^\d{1,3}$/.test(i.days))) return setErr('Days after event must be a whole number (0–730).');
    setBusy(true);
    setErr('');
    try {
      const body = { ...h, event_type: h.event_type || null, items: list.map((i) => ({ name: i.name.trim(), description: i.description, days_after_event: i.days === '' ? null : Number(i.days) })) };
      if (template.id) await api.put(`deliverable-templates/${template.id}`, body);
      else await api.post('deliverable-templates', body);
      toast('Template saved');
      onSaved();
      onClose();
    } catch (e: any) {
      setErr(e.message);
    }
    setBusy(false);
  };
  return (
    <Modal open={!!template} onClose={onClose} size="lg" title={template?.id ? 'Edit template' : 'New deliverable template'} footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save template</Button></>}>
      <div className="space-y-4">
        {err && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{err}</p>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Template name" required><Input value={h.name} onChange={(e) => setH({ ...h, name: e.target.value })} placeholder="Wedding Package" /></Field>
          <Field label="Event type"><Select value={h.event_type} onChange={(e) => setH({ ...h, event_type: e.target.value })} options={config.event_types} placeholder="Any" /></Field>
          <Field label="Description" className="sm:col-span-2"><Input value={h.description} onChange={(e) => setH({ ...h, description: e.target.value })} /></Field>
        </div>
        <div>
          <p className="label">Deliverables</p>
          <ul className="space-y-2">
            {items.map((it, i) => (
              <li key={i} className="grid gap-2 rounded-lg border border-line p-2 sm:grid-cols-[1fr_110px_auto] sm:items-center">
                <Input value={it.name} onChange={(e) => setItem(i, { name: e.target.value })} placeholder="e.g. Edited Photos" className="py-1.5 text-sm" aria-label="Deliverable name" />
                <Input value={it.days} onChange={(e) => setItem(i, { days: e.target.value.replace(/\D/g, '') })} inputMode="numeric" placeholder="Days after" className="py-1.5 text-xs" aria-label="Days after last event" />
                <div className="flex justify-end">
                  <button disabled={i === 0} onClick={() => move(i, -1)} className="rounded p-1.5 text-faint hover:text-ink disabled:opacity-30" aria-label="Move up"><ArrowUp className="h-4 w-4" /></button>
                  <button disabled={i === items.length - 1} onClick={() => move(i, 1)} className="rounded p-1.5 text-faint hover:text-ink disabled:opacity-30" aria-label="Move down"><ArrowDown className="h-4 w-4" /></button>
                  <button onClick={() => setItems((l) => l.filter((_, j) => j !== i))} className="rounded p-1.5 text-faint hover:text-red-600" aria-label="Remove"><X className="h-4 w-4" /></button>
                </div>
              </li>
            ))}
          </ul>
          <Button size="sm" variant="ghost" className="mt-2" icon={<Plus className="h-4 w-4" />} onClick={() => setItems((l) => [...l, { name: '', description: '', days: '' }])}>Add deliverable</Button>
          <p className="mt-1 text-xs text-muted">Quick add: {config.deliverable_presets.slice(0, 8).map((p, n) => <button key={p} onClick={() => setItems((l) => (l.some((x) => x.name.toLowerCase() === p.toLowerCase()) ? l : [...l.filter((x) => x.name.trim()), { name: p, description: '', days: '' }]))} className="text-accent-ink hover:underline">{p}{n < 7 ? ', ' : ''}</button>)}</p>
        </div>
      </div>
    </Modal>
  );
}

function ServicesTab() {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['services', 'all'], queryFn: () => api.get<any[]>('services') });
  const [edit, setEdit] = useState<any>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['services'] });
  const del = async (s: any) => {
    if (!(await confirm({ title: `Delete ${s.name}?`, message: 'Existing quotations keep their lines. You can also just mark it inactive.', danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`services/${s.id}`).then(refresh, (e) => toast(e.message, 'error'));
  };
  const toggle = (s: any) => api.put(`services/${s.id}`, { is_active: !s.is_active }).then(refresh, (e) => toast(e.message, 'error'));
  const grouped: Record<string, any[]> = {};
  (q.data || []).forEach((s) => (grouped[s.category] ||= []).push(s));
  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <p className="text-sm text-muted">Your price list. These appear in the quotation builder.</p>
        <Button variant="primary" size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>Add service</Button>
      </div>
      {!q.data?.length ? (
        <EmptyState title="No services yet" description="Add your packages and add-ons with their prices." />
      ) : (
        <div className="space-y-5">
          {Object.entries(grouped).map(([cat, list]) => (
            <div key={cat}>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-faint">{config.service_categories[cat] || cat}</p>
              <ul className="divide-y divide-line rounded-xl border border-line">
                {list.map((s) => (
                  <li key={s.id} className={clsx('flex items-center gap-3 px-4 py-2.5', !s.is_active && 'opacity-60')}>
                    <div className="min-w-0 flex-1"><p className="text-sm font-medium">{s.name}</p>{s.description && <p className="truncate text-xs text-muted">{s.description}</p>}</div>
                    <span className="tabular text-sm">{inr(s.price)}{s.unit ? <span className="text-xs text-muted"> / {s.unit}</span> : ''}</span>
                    <Toggle checked={!!s.is_active} onChange={() => toggle(s)} />
                    <button onClick={() => setEdit(s)} className="rounded p-1.5 text-faint hover:text-ink" aria-label="Edit"><Pencil className="h-4 w-4" /></button>
                    <button onClick={() => del(s)} className="rounded p-1.5 text-faint hover:text-red-600" aria-label="Delete"><Trash2 className="h-4 w-4" /></button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
      <ServiceModal service={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </div>
  );
}

function ServiceModal({ service, onClose, onSaved }: { service: any; onClose: () => void; onSaved: () => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const f = useForm({ category: 'photography', name: '', description: '', price: '', unit: '' });
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!service) return;
    f.setValues(service.id ? { category: service.category, name: service.name, description: service.description || '', price: String(service.price), unit: service.unit || '' } : { category: Object.keys(config.service_categories)[0], name: '', description: '', price: '', unit: 'day' });
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [service]);
  const submit = async () => {
    const e = validate(f.values, { name: ['required'], price: ['required', 'positive'], category: ['required'] }, { name: 'Service name' });
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      const body = { ...f.values, price: Number(f.values.price) };
      if (service.id) await api.put(`services/${service.id}`, body);
      else await api.post('services', body);
      toast('Service saved');
      onSaved();
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal open={!!service} onClose={onClose} title={service?.id ? 'Edit service' : 'Add service'} size="sm" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={submit}>Save</Button></>}>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Name" required error={f.errors.name} className="col-span-2"><Input {...f.bind('name')} autoFocus placeholder="Candid photography" /></Field>
        <Field label="Category" className="col-span-2"><Select {...f.bind('category')} options={config.service_categories} /></Field>
        <Field label="Price (₹)" required error={f.errors.price}><Input {...f.bind('price')} inputMode="decimal" /></Field>
        <Field label="Per"><Input {...f.bind('unit')} placeholder="day, event, album" /></Field>
        <Field label="Description" className="col-span-2"><Textarea rows={2} {...f.bind('description')} /></Field>
      </div>
    </Modal>
  );
}

/* ---------- WhatsApp ---------- */
const VARS = ['client_name', 'studio_name', 'studio_phone', 'event_type', 'event_name', 'event_date_text', 'event_dates', 'event_time_text', 'venue', 'booking_number', 'total_amount', 'paid_amount', 'pending_amount', 'due_amount', 'due_date', 'due_label', 'quotation_number', 'quotation_amount', 'quotation_link', 'valid_until', 'upi_text'];

function WhatsAppTab() {
  const { studio } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const save = useSaveSettings();
  const templates = useQuery({ queryKey: ['wa-templates'], queryFn: () => api.get<any[]>('whatsapp/templates') });
  const [edit, setEdit] = useState<any>(null);
  const s = studio.settings || {};
  const allowed = studio.plan_info.whatsapp_api;
  const [cred, setCred] = useState({ wa_phone_id: s.wa_phone_id || '', wa_token: s.wa_token || '' });
  const [busy, setBusy] = useState(false);
  const del = async (t: any) => {
    if (!(await confirm({ title: `Delete template "${t.name}"?`, danger: true, confirmLabel: 'Delete' }))) return;
    await api.del(`whatsapp/templates/${t.id}`).then(() => qc.invalidateQueries({ queryKey: ['wa-templates'] }), (e) => toast(e.message, 'error'));
  };
  const configured = !!(s.wa_phone_id && s.wa_token);
  return (
    <div className="space-y-8">
      <section>
        <CardHeader className="px-0 pt-0" title="Message templates" subtitle="Used for click-to-chat. Placeholders like {{client_name}} are filled in from the lead, booking or quotation." actions={<Button size="sm" variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>New template</Button>} />
        <ul className="divide-y divide-line rounded-xl border border-line">
          {(templates.data || []).map((t) => (
            <li key={t.id} className="flex items-start gap-3 px-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">{t.name}{!t.is_active && <Badge>Off</Badge>}</p>
                <p className="mt-0.5 line-clamp-2 whitespace-pre-line text-xs text-muted">{t.body}</p>
              </div>
              <button onClick={() => setEdit(t)} className="rounded p-1.5 text-faint hover:text-ink" aria-label="Edit"><Pencil className="h-4 w-4" /></button>
              <button onClick={() => del(t)} className="rounded p-1.5 text-faint hover:text-red-600" aria-label="Delete"><Trash2 className="h-4 w-4" /></button>
            </li>
          ))}
        </ul>
      </section>
      <section className="max-w-2xl">
        <h3 className="text-sm font-semibold">WhatsApp Business Cloud API (optional)</h3>
        <p className="mt-1 text-sm text-muted">
          Without this, WhatsApp buttons open the chat on your phone or WhatsApp Web with the message ready, and you press send. With your own Meta Cloud API credentials the CRM can send text messages directly. Note: Meta only delivers free-form text inside the 24-hour customer-service window; outside it, Meta requires pre-approved templates, which this version does not manage.
        </p>
        <details className="mt-3 rounded-lg border border-line px-3 py-2 text-sm">
          <summary className="cursor-pointer font-medium">How to get these from Meta</summary>
          <ol className="mt-2 list-decimal space-y-1.5 pl-5 text-muted">
            <li>Open <a className="text-accent-ink" href="https://developers.facebook.com/apps" target="_blank" rel="noreferrer">developers.facebook.com</a> → Create app → type "Business" → add the <b>WhatsApp</b> product.</li>
            <li>In WhatsApp → API setup, add your business phone number and verify it with the OTP. This number cannot stay on the normal WhatsApp app.</li>
            <li>Copy the <b>Phone Number ID</b> shown there.</li>
            <li>In Business settings → Users → System users, create a system user (Admin), assign your app and WhatsApp account, then <b>Generate token</b> with the permissions whatsapp_business_messaging and whatsapp_business_management. Choose "Never expire". This is the permanent access token.</li>
            <li>Add a payment method in WhatsApp Manager, because Meta charges per conversation.</li>
            <li>Paste both values below and save, then press "Send via API" on any WhatsApp button and send a test to your own number.</li>
          </ol>
        </details>
        {!allowed ? (
          <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">Direct API sending is part of the Professional and Enterprise plans.</p>
        ) : (
          <div className="mt-4 space-y-4">
            <Badge tone={configured ? 'green' : 'gray'}>{configured ? 'Credentials saved' : 'Not configured'}</Badge>
            <Field label="Phone Number ID" hint="Meta Business → WhatsApp → API setup"><Input value={cred.wa_phone_id} onChange={(e) => setCred({ ...cred, wa_phone_id: e.target.value })} /></Field>
            <Field label="Permanent access token" hint="Stored for this studio only and never shown again in full."><Input value={cred.wa_token} onChange={(e) => setCred({ ...cred, wa_token: e.target.value })} type="password" autoComplete="off" /></Field>
            <div className="flex gap-2">
              <Button variant="primary" loading={busy} onClick={async () => (setBusy(true), await save({ settings: cred }), setBusy(false))}>Save credentials</Button>
              {configured && <Button onClick={async () => { setCred({ wa_phone_id: '', wa_token: '' }); await save({ settings: { wa_phone_id: '', wa_token: '' } }); }}>Remove</Button>}
            </div>
          </div>
        )}
      </section>
      <TemplateModal template={edit} onClose={() => setEdit(null)} />
    </div>
  );
}

function TemplateModal({ template, onClose }: { template: any; onClose: () => void }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [v, setV] = useState({ name: '', body: '', is_active: true });
  const [busy, setBusy] = useState(false);
  const ref = useRef<HTMLTextAreaElement>(null);
  useEffect(() => {
    if (template) setV(template.id ? { name: template.name, body: template.body, is_active: !!template.is_active } : { name: '', body: 'Hi {{client_name}}, ', is_active: true });
  }, [template]);
  const insert = (k: string) => {
    const el = ref.current;
    const tag = `{{${k}}}`;
    if (!el) return setV({ ...v, body: v.body + tag });
    const s = el.selectionStart;
    setV({ ...v, body: v.body.slice(0, s) + tag + v.body.slice(el.selectionEnd) });
    setTimeout(() => (el.focus(), el.setSelectionRange(s + tag.length, s + tag.length)), 0);
  };
  const submit = async () => {
    if (!v.name.trim() || !v.body.trim()) return toast('Name and message are required.', 'error');
    setBusy(true);
    try {
      if (template.id) await api.put(`whatsapp/templates/${template.id}`, v);
      else await api.post('whatsapp/templates', v);
      qc.invalidateQueries({ queryKey: ['wa-templates'] });
      toast('Template saved');
      onClose();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal open={!!template} onClose={onClose} title={template?.id ? 'Edit template' : 'New template'} size="lg" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={submit}>Save</Button></>}>
      <div className="space-y-4">
        <Field label="Name" required><Input value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} /></Field>
        <Field label="Message" required><Textarea ref={ref} rows={8} value={v.body} onChange={(e) => setV({ ...v, body: e.target.value })} /></Field>
        <div>
          <p className="label">Insert a placeholder</p>
          <div className="flex flex-wrap gap-1.5">{VARS.map((k) => <button key={k} onClick={() => insert(k)} className="rounded border border-line px-1.5 py-0.5 font-mono text-2xs text-muted hover:bg-subtle hover:text-ink">{`{{${k}}}`}</button>)}</div>
        </div>
        <Checkbox checked={v.is_active} onChange={(x) => setV({ ...v, is_active: x })} label="Active" />
      </div>
    </Modal>
  );
}

/* ---------- My profile ---------- */
function ProfileTab() {
  const { me, member, config, refresh } = useAuth();
  const { toast } = useUi();
  const f = useForm({ name: member.name || me.user?.name || '', phone: member.phone || '' });
  const p = useForm({ current: '', password: '', confirm: '' });
  const [busy, setBusy] = useState(false);
  const [pbusy, setPbusy] = useState(false);
  const saveProfile = async () => {
    const e = validate(f.values, { name: ['required'], phone: ['phone'] });
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      await api.post('me', f.values);
      refresh();
      toast('Profile saved');
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  const savePw = async () => {
    const e = validate(p.values, { current: ['required'], password: ['required'] }, { current: 'Current password', password: 'New password' });
    if (!e.password && p.values.password.length < 8) e.password = 'At least 8 characters.';
    if (!e.password && p.values.password !== p.values.confirm) e.confirm = 'Passwords do not match.';
    p.setErrors(e);
    if (Object.keys(e).length) return;
    setPbusy(true);
    try {
      await api.post('me/password', { current: p.values.current, password: p.values.password });
      p.reset();
      toast('Password changed');
    } catch (err) {
      p.fromError(err);
      toast((err as Error).message, 'error');
    }
    setPbusy(false);
  };
  return (
    <div className="grid max-w-3xl gap-8 md:grid-cols-2">
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Profile</h3>
        <p className="text-sm text-muted">{me.user?.email} · {config.roles[member.role]}</p>
        <Field label="Name" required error={f.errors.name}><Input {...f.bind('name')} /></Field>
        <Field label="Phone" error={f.errors.phone}><Input {...f.bind('phone')} inputMode="tel" /></Field>
        <Button variant="primary" loading={busy} onClick={saveProfile}>Save profile</Button>
      </section>
      <section className="space-y-4">
        <h3 className="text-sm font-semibold">Change password</h3>
        <Field label="Current password" error={p.errors.current}><Input type="password" autoComplete="current-password" {...p.bind('current')} /></Field>
        <Field label="New password" error={p.errors.password}><Input type="password" autoComplete="new-password" {...p.bind('password')} /></Field>
        <Field label="Confirm new password" error={p.errors.confirm}><Input type="password" autoComplete="new-password" {...p.bind('confirm')} /></Field>
        <Button loading={pbusy} onClick={savePw}>Change password</Button>
      </section>
    </div>
  );
}

/* ---------- Plan ---------- */
function PlanTab() {
  const { studio } = useAuth();
  const b = studio.billing;
  return (
    <div className="max-w-xl">
      <p className="text-sm">
        You are on the <b>{b?.label || studio.plan_info.label}</b> plan
        {b?.state === 'trial' ? `, free trial with ${b.days_left} ${b.days_left === 1 ? 'day' : 'days'} left` : b?.ends_at ? `, until ${fmtDate(b.ends_at)}` : ''}.
      </p>
      <p className="mt-1 text-sm text-muted">See usage, upgrade, pay online and download your billing history on the Billing page.</p>
      <Link to="/billing" className="mt-4 inline-flex h-9 items-center rounded-lg bg-accent px-4 text-sm font-medium text-white shadow-sm hover:bg-accent/90 dark:text-slate-950">Open Billing & plan</Link>
    </div>
  );
}

/* ---------- Data ---------- */
function DataTab() {
  const { member } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const [busy, setBusy] = useState('');
  const [confirmText, setConfirmText] = useState('');
  const [resetOpen, setResetOpen] = useState(false);
  const exportLeads = async () => {
    setBusy('export');
    try {
      await api.download('leads/export', {}, 'all-leads.csv');
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
  };
  const sample = async () => {
    setBusy('sample');
    try {
      const r = await api.post('settings/sample-data', {});
      qc.invalidateQueries();
      toast(`Sample data added: ${r.leads ?? ''} leads, ${r.bookings ?? ''} bookings`);
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
  };
  const reset = async () => {
    setBusy('reset');
    try {
      await api.post('settings/reset-data', { confirm: confirmText });
      qc.invalidateQueries();
      toast('All business data deleted');
      setResetOpen(false);
      setConfirmText('');
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
  };
  return (
    <div className="max-w-2xl space-y-6">
      <section className="rounded-xl border border-line p-4">
        <h3 className="text-sm font-semibold">Export</h3>
        <p className="mt-1 text-sm text-muted">Download every lead as CSV (opens in Excel). Reports have their own CSV buttons.</p>
        <Button className="mt-3" size="sm" loading={busy === 'export'} onClick={exportLeads}>Export all leads</Button>
      </section>
      <section className="rounded-xl border border-line p-4">
        <h3 className="text-sm font-semibold">Sample data</h3>
        <p className="mt-1 text-sm text-muted">Fill an empty studio with realistic demo leads, bookings, payments and team members to explore the CRM. Only works when you have no leads yet.</p>
        <Button className="mt-3" size="sm" loading={busy === 'sample'} onClick={sample}>Load sample data</Button>
      </section>
      {member.role === 'owner' && (
        <section className="rounded-xl border border-red-200 p-4 dark:border-red-500/30">
          <h3 className="text-sm font-semibold text-red-700 dark:text-red-300">Delete all business data</h3>
          <p className="mt-1 text-sm text-muted">Removes every lead, client, quotation, booking, payment, expense, task and file. Your team, settings, services and templates stay. This cannot be undone.</p>
          <Button className="mt-3" size="sm" variant="danger" onClick={() => setResetOpen(true)}>Delete all data…</Button>
        </section>
      )}
      <Modal open={resetOpen} onClose={() => setResetOpen(false)} title="Delete all business data?" size="sm" footer={<><Button onClick={() => setResetOpen(false)}>Cancel</Button><Button variant="danger" disabled={confirmText !== 'DELETE'} loading={busy === 'reset'} onClick={reset}>Delete everything</Button></>}>
        <p className="mb-3 text-sm text-muted">Type <b className="text-ink">DELETE</b> to confirm.</p>
        <Input value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoFocus />
      </Modal>
    </div>
  );
}

/* ---------- Online payments (Razorpay) ---------- */
function RazorpayTab() {
  const { studio } = useAuth();
  const { toast } = useUi();
  const save = useSaveSettings();
  const s = studio.settings || {};
  const [v, setV] = useState({ rzp_key_id: s.rzp_key_id || '', rzp_key_secret: s.rzp_key_secret || '', rzp_webhook_secret: s.rzp_webhook_secret || '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const submit = async (body: any) => {
    setBusy(true);
    setErrors({});
    await save({ settings: body }, { fromError: (e: any) => setErrors(e?.errors || {}) });
    setBusy(false);
  };
  const copy = (t: string) => navigator.clipboard.writeText(t).then(() => toast('Copied'));
  return (
    <div className="grid max-w-4xl gap-8 lg:grid-cols-[1fr_1fr]">
      <section>
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-semibold">Razorpay</h3>
          <Badge tone={s.rzp_enabled ? 'green' : 'gray'}>{s.rzp_enabled ? 'Connected' : 'Not connected'}</Badge>
        </div>
        <p className="mt-1 text-sm text-muted">Send clients a Razorpay payment link from any booking. When the client pays, the payment and receipt are added to the booking automatically.</p>
        <div className="mt-4 space-y-4">
          <Field label="Key ID" error={errors.rzp_key_id}><Input value={v.rzp_key_id} onChange={(e) => setV({ ...v, rzp_key_id: e.target.value })} placeholder="rzp_live_XXXXXXXX" autoComplete="off" /></Field>
          <Field label="Key Secret" hint="Stored for this studio only and never shown again in full." error={errors.rzp_key_secret}><Input type="password" value={v.rzp_key_secret} onChange={(e) => setV({ ...v, rzp_key_secret: e.target.value })} autoComplete="off" /></Field>
          <Field label="Webhook secret" hint="Any strong password you also type in Razorpay's webhook form." error={errors.rzp_webhook_secret}><Input type="password" value={v.rzp_webhook_secret} onChange={(e) => setV({ ...v, rzp_webhook_secret: e.target.value })} autoComplete="off" /></Field>
          <div className="flex gap-2">
            <Button variant="primary" loading={busy} onClick={() => submit(v)}>Save</Button>
            {s.rzp_enabled && (
              <Button onClick={() => { const e = { rzp_key_id: '', rzp_key_secret: '', rzp_webhook_secret: '' }; setV(e); submit(e); }}>Disconnect</Button>
            )}
          </div>
        </div>
      </section>
      <section className="text-sm">
        <h3 className="font-semibold">How to connect</h3>
        <ol className="mt-2 list-decimal space-y-2 pl-5 text-muted">
          <li>Sign in to the <a className="text-accent-ink" href="https://dashboard.razorpay.com" target="_blank" rel="noreferrer">Razorpay Dashboard</a> and finish KYC so live payments are allowed. You can try everything first in Test Mode.</li>
          <li>Go to Account &amp; Settings → <b>API Keys</b> → Generate key. Copy the Key ID and Key Secret here.</li>
          <li>Go to Account &amp; Settings → <b>Webhooks</b> → Add new webhook:
            <div className="mt-1.5 flex items-center gap-2">
              <code className="min-w-0 flex-1 truncate rounded-md bg-subtle px-2 py-1.5 text-xs">{s.rzp_webhook || ''}</code>
              <Button size="xs" onClick={() => copy(s.rzp_webhook || '')}>Copy</Button>
            </div>
            Use the same webhook secret as here, and tick the events <b>payment_link.paid</b>, <b>payment_link.cancelled</b> and <b>payment_link.expired</b>.
          </li>
          <li>Open a booking → Payments → <b>Payment link</b>, then share it on WhatsApp.</li>
        </ol>
        <p className="mt-3 text-muted">Without the webhook, links still work: press "Check status" on the link to pull the payment in.</p>
      </section>
    </div>
  );
}
