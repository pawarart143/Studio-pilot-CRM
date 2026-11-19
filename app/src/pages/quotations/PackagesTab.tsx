import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, Package, Pencil, Plus, Trash2, X } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { inr } from '@/lib/format';
import { Badge, Button, EmptyState, ErrorState, Field, Input, Modal, Select, TableSkeleton, Textarea, Toggle, useUi } from '@/components/ui';
import { CoverageEditor, LinesEditor, num, r2 } from './parts';

type PItem = { key: string; service_id?: number | null; category?: string | null; name: string; description: string; qty: string; rate: string; unit?: string | null };
let seq = 0;
const k = () => 'p' + ++seq;

/** Settings → Quote packages: reusable sets of services with rates, deliverables and the team per day. */
export default function PackagesTab() {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['quote-packages', 'all'], queryFn: () => api.get<any[]>('quote-packages') });
  const [edit, setEdit] = useState<any>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: ['quote-packages'] });
  const del = async (p: any) => {
    if (!(await confirm({ title: `Delete package "${p.name}"?`, message: 'Quotations that already used it keep their lines and prices.', danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`quote-packages/${p.id}`);
      toast('Package deleted');
      refresh();
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const toggle = (p: any) => api.put(`quote-packages/${p.id}`, { is_active: !p.is_active }).then(refresh, (e) => toast(e.message, 'error'));
  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-xl text-sm text-muted">Packages fill a quotation in one tap: services with your rates, the deliverables and the team per event day. Edit any line on the quotation afterwards. Rates come from your price list when you add them.</p>
        <Button variant="primary" size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>New package</Button>
      </div>
      {q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.isLoading ? (
        <TableSkeleton rows={3} cols={3} />
      ) : !q.data?.length ? (
        <EmptyState icon={<Package className="h-5 w-5" />} title="No packages yet" description="Create packages like Wedding – Essentials or Pre-Wedding Shoot so you never re-enter prices." action={<Button variant="primary" onClick={() => setEdit({})}>New package</Button>} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {q.data.map((p) => (
            <li key={p.id} className={clsx('rounded-xl border border-line p-4', !p.is_active && 'opacity-60')}>
              <div className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{p.name} {p.event_type && <Badge className="ml-1">{config.event_types[p.event_type]}</Badge>}</p>
                  {p.description && <p className="mt-0.5 text-xs text-muted">{p.description}</p>}
                </div>
                <span className="tabular shrink-0 font-semibold">{inr(p.total)}</span>
              </div>
              <ul className="mt-3 space-y-0.5 text-sm">
                {p.items.map((i: any) => (
                  <li key={i.id} className="flex justify-between gap-2"><span className="truncate">{i.name}{Number(i.qty) !== 1 ? ` × ${Number(i.qty)}` : ''}</span><span className="tabular text-xs text-muted">{inr(r2(i.qty * i.rate))}</span></li>
                ))}
              </ul>
              {p.deliverables?.length > 0 && <p className="mt-2 text-xs text-muted">{p.deliverables.length} deliverables</p>}
              <div className="mt-3 flex items-center gap-2 border-t border-line pt-3">
                <Toggle checked={!!p.is_active} onChange={() => toggle(p)} label={<span className="text-xs">Active</span>} />
                <button onClick={() => setEdit(p)} className="ml-auto rounded p-1.5 text-faint hover:text-ink" aria-label={`Edit ${p.name}`}><Pencil className="h-4 w-4" /></button>
                <button onClick={() => del(p)} className="rounded p-1.5 text-faint hover:text-red-600" aria-label={`Delete ${p.name}`}><Trash2 className="h-4 w-4" /></button>
              </div>
            </li>
          ))}
        </ul>
      )}
      <PackageModal pkg={edit} onClose={() => setEdit(null)} onSaved={refresh} />
    </div>
  );
}

function PackageModal({ pkg, onClose, onSaved }: { pkg: any; onClose: () => void; onSaved: () => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const services = useQuery({ queryKey: ['services', 'active'], queryFn: () => api.get<any[]>('services', { active: 1 }), enabled: !!pkg });
  const [h, setH] = useState({ name: '', event_type: '', description: '' });
  const [items, setItems] = useState<PItem[]>([]);
  const [deliverables, setDeliverables] = useState<string[]>([]);
  const [coverage, setCoverage] = useState<Record<string, number>>({});
  const [pick, setPick] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!pkg) return;
    setH({ name: pkg.name || '', event_type: pkg.event_type || '', description: pkg.description || '' });
    setItems((pkg.items || []).map((i: any) => ({ key: k(), service_id: i.service_id, category: i.category, name: i.name, description: i.description || '', qty: String(i.qty), rate: String(i.rate), unit: i.unit || '' })));
    setDeliverables(pkg.deliverables || []);
    setCoverage({ ...(pkg.coverage || {}) });
    setErr('');
    setPick('');
  }, [pkg]);
  const total = useMemo(() => r2(items.reduce((s, i) => s + r2(num(i.qty) * num(i.rate)), 0)), [items]);
  const add = (v: string) => {
    if (!v) return;
    if (v === '__custom') setItems((l) => [...l, { key: k(), name: '', description: '', qty: '1', rate: '' }]);
    else {
      const s = (services.data || []).find((x) => String(x.id) === v);
      if (s) setItems((l) => [...l, { key: k(), service_id: s.id, category: s.category, name: s.name, description: s.description || '', qty: '1', rate: String(s.price), unit: s.unit || '' }]);
    }
    setPick('');
  };
  const set = (key: string, patch: Partial<PItem>) => setItems((l) => l.map((i) => (i.key === key ? { ...i, ...patch } : i)));
  const submit = async () => {
    if (!h.name.trim()) return setErr('Give the package a name.');
    if (!items.length) return setErr('Add at least one service.');
    const bad = items.findIndex((i) => !i.name.trim() || num(i.qty) <= 0 || i.rate === '' || num(i.rate) < 0);
    if (bad >= 0) return setErr(`Line ${bad + 1} needs a name, a quantity above 0 and a rate.`);
    setBusy(true);
    setErr('');
    const body = { ...h, event_type: h.event_type || null, deliverables, coverage, items: items.map(({ key, ...i }) => ({ ...i, service_id: i.service_id || null, category: i.category || null, qty: num(i.qty), rate: num(i.rate), unit: i.unit || null })) };
    try {
      if (pkg.id) await api.put(`quote-packages/${pkg.id}`, body);
      else await api.post('quote-packages', body);
      toast(pkg.id ? 'Package saved' : 'Package created');
      onSaved();
      onClose();
    } catch (e: any) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };
  const options: [string, string][] = [['__custom', 'Custom line…'], ...((services.data || []).map((s) => [String(s.id), `${config.service_categories[s.category] || s.category} · ${s.name} (${inr(s.price)})`]) as [string, string][])];
  return (
    <Modal open={!!pkg} onClose={onClose} size="lg" title={pkg?.id ? 'Edit package' : 'New package'} subtitle={`Package total ${inr(total)}`} footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={submit}>{pkg?.id ? 'Save package' : 'Create package'}</Button></>}>
      <div className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Package name" required><Input value={h.name} onChange={(e) => setH({ ...h, name: e.target.value })} maxLength={190} placeholder="e.g. Wedding – Signature" /></Field>
          <Field label="Best for"><Select value={h.event_type} onChange={(e) => setH({ ...h, event_type: e.target.value })} options={config.event_types} placeholder="Any event" /></Field>
          <Field label="Short description" className="sm:col-span-2"><Textarea rows={2} value={h.description} onChange={(e) => setH({ ...h, description: e.target.value })} /></Field>
        </div>
        <div>
          <p className="label">Services</p>
          <div className="divide-y divide-line rounded-xl border border-line">
            {items.length === 0 && <p className="px-3 py-4 text-center text-sm text-muted">Add services from your price list below.</p>}
            {items.map((i, idx) => (
              <div key={i.key} className="grid grid-cols-12 items-center gap-2 px-3 py-2">
                <Input className="col-span-12 sm:col-span-6" value={i.name} onChange={(e) => set(i.key, { name: e.target.value })} placeholder="Service name" maxLength={190} aria-label={`Line ${idx + 1} name`} />
                <Input className="col-span-3 text-right sm:col-span-2" value={i.qty} onChange={(e) => set(i.key, { qty: e.target.value })} inputMode="decimal" aria-label={`Line ${idx + 1} quantity`} />
                <Input className="col-span-5 text-right sm:col-span-2" value={i.rate} onChange={(e) => set(i.key, { rate: e.target.value })} inputMode="decimal" placeholder="Rate" aria-label={`Line ${idx + 1} rate`} />
                <div className="col-span-4 flex justify-end gap-0.5 sm:col-span-2">
                  <button type="button" className="rounded p-1 text-faint hover:text-ink disabled:opacity-30" disabled={idx === 0} onClick={() => setItems((l) => { const n = [...l]; [n[idx - 1], n[idx]] = [n[idx], n[idx - 1]]; return n; })} aria-label="Move up"><ArrowUp className="h-3.5 w-3.5" /></button>
                  <button type="button" className="rounded p-1 text-faint hover:text-ink disabled:opacity-30" disabled={idx === items.length - 1} onClick={() => setItems((l) => { const n = [...l]; [n[idx + 1], n[idx]] = [n[idx], n[idx + 1]]; return n; })} aria-label="Move down"><ArrowDown className="h-3.5 w-3.5" /></button>
                  <button type="button" className="rounded p-1 text-faint hover:text-red-600" onClick={() => setItems((l) => l.filter((x) => x.key !== i.key))} aria-label="Remove"><X className="h-3.5 w-3.5" /></button>
                </div>
              </div>
            ))}
          </div>
          <Select className="mt-2" value={pick} onChange={(e) => add(e.target.value)} options={options} placeholder={services.isLoading ? 'Loading price list…' : '+ Add a service'} />
        </div>
        <div>
          <p className="label">Team per event day</p>
          <CoverageEditor value={coverage} onChange={setCoverage} />
        </div>
        <div>
          <p className="label">Deliverables</p>
          <LinesEditor value={deliverables} onChange={setDeliverables} presets={config.quote_deliverable_presets} placeholder="Add a deliverable" />
        </div>
        {err && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{err}</p>}
      </div>
    </Modal>
  );
}
