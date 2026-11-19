import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Copy, ExternalLink, Eye, KeyRound, Link2, Lock, RefreshCw, ShieldCheck, Unlock } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTeam } from '@/lib/data';
import { useForm } from '@/lib/form';
import { fmtDateTime } from '@/lib/format';
import { deliverableTone } from '@/lib/tones';
import { Badge, Button, Field, Input, Modal, Select, Textarea, Toggle, useUi } from './ui';
import { WhatsAppModal } from './shared';

/* ---------- Progress ---------- */
export function ProgressBar({ value, size = 'md', className }: { value: number; size?: 'sm' | 'md' | 'lg'; className?: string }) {
  const v = Math.max(0, Math.min(100, Math.round(value || 0)));
  return (
    <div className={clsx('w-full overflow-hidden rounded-full bg-subtle', size === 'sm' && 'h-1.5', size === 'md' && 'h-2', size === 'lg' && 'h-3', className)} role="progressbar" aria-valuenow={v} aria-valuemin={0} aria-valuemax={100}>
      <div className={clsx('h-full rounded-full transition-[width] duration-300', v >= 100 ? 'bg-emerald-500' : 'bg-accent')} style={{ width: `${v}%` }} />
    </div>
  );
}

export function DeliverableBadge({ status }: { status: string }) {
  const { config } = useAuth();
  return <Badge tone={deliverableTone[status] || 'gray'}>{config.deliverable_statuses[status] || status}</Badge>;
}

const isUrl = (s: string) => /^https?:\/\/\S+\.\S+/i.test(s.trim());

/* ---------- Edit one deliverable ---------- */
export function DeliverableModal({ open, onClose, deliverable, bookingId, onSaved }: { open: boolean; onClose: () => void; deliverable?: any; bookingId?: number; onSaved?: () => void }) {
  const { config, can, member } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const team = useTeam();
  const full = can('deliverables.edit');
  const isNew = !deliverable?.id;
  const blank = { name: '', description: '', assigned_to: '', expected_date: '', status: 'not_started', progress: '0', delivery_link: '', admin_notes: '' };
  const f = useForm(blank);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!open) return;
    f.setValues(
      deliverable?.id
        ? { name: deliverable.name, description: deliverable.description || '', assigned_to: deliverable.assigned_to ? String(deliverable.assigned_to) : '', expected_date: deliverable.expected_date || '', status: deliverable.status, progress: String(deliverable.progress ?? 0), delivery_link: deliverable.delivery_link || '', admin_notes: deliverable.admin_notes || '' }
        : blank,
    );
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, deliverable?.id]);
  const setStatus = (s: string) => {
    f.set('status', s);
    f.set('progress', String(s === 'delivered' ? 100 : config.deliverable_progress[s] ?? f.values.progress));
  };
  const save = async () => {
    const v = f.values;
    const e: Record<string, string> = {};
    if (full && !v.name.trim()) e.name = 'Name is required.';
    const p = Number(v.progress);
    if (!Number.isInteger(p) || p < 0 || p > 100) e.progress = 'Enter 0 to 100.';
    if (v.delivery_link && !isUrl(v.delivery_link)) e.delivery_link = 'Enter a full link starting with https://';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const body: any = full
      ? { ...v, assigned_to: v.assigned_to || null, expected_date: v.expected_date || null, progress: p, delivery_link: v.delivery_link || null }
      : { status: v.status, progress: p, delivery_link: v.delivery_link || null };
    try {
      if (isNew) await api.post(`bookings/${bookingId}/deliverables`, body);
      else await api.put(`deliverables/${deliverable.id}`, body);
      toast(isNew ? 'Deliverable added' : 'Deliverable updated');
      ['booking', 'deliverables', 'team'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onSaved?.();
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  const crew = (team.data || []).filter((m) => m.status === 'active' || String(m.id) === f.values.assigned_to);
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isNew ? 'Add deliverable' : full ? `Edit ${deliverable?.name}` : `Update ${deliverable?.name}`}
      subtitle={!full && deliverable?.assigned_to === member.id ? 'You can update the status, progress and delivery link.' : undefined}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>{isNew ? 'Add' : 'Save'}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {full && (
          <>
            <Field label="Deliverable name" required error={f.errors.name} className="sm:col-span-2">
              <Input {...f.bind('name')} list="deliverable-presets" placeholder="Edited Photographs" autoFocus={isNew} />
              <datalist id="deliverable-presets">{config.deliverable_presets.map((p) => <option key={p} value={p} />)}</datalist>
            </Field>
            <Field label="Description" className="sm:col-span-2"><Textarea rows={2} {...f.bind('description')} placeholder="What the client receives" /></Field>
            <Field label="Assigned team member">
              <Select {...f.bind('assigned_to')} options={crew.map((m) => [String(m.id), m.name + (m.team_role ? ' · ' + config.team_roles[m.team_role] : '')] as [string, string])} placeholder="Not assigned" />
            </Field>
            <Field label="Expected delivery date"><Input type="date" {...f.bind('expected_date')} /></Field>
          </>
        )}
        <Field label="Status"><Select value={f.values.status} onChange={(e) => setStatus(e.target.value)} options={config.deliverable_statuses} /></Field>
        <Field label={`Progress · ${f.values.progress || 0}%`} error={f.errors.progress}>
          <div className="flex items-center gap-3 pt-1.5">
            <input type="range" min={0} max={100} step={5} value={Number(f.values.progress) || 0} onChange={(e) => f.set('progress', e.target.value)} className="w-full accent-[rgb(var(--accent))]" disabled={f.values.status === 'delivered'} aria-label="Progress" />
          </div>
        </Field>
        <Field label="Delivery link" error={f.errors.delivery_link} hint="Google Drive, Pixieset, WeTransfer, YouTube… The client sees it on the portal." className="sm:col-span-2">
          <Input {...f.bind('delivery_link')} placeholder="https://" inputMode="url" />
        </Field>
        {full && (
          <Field label="Admin notes" hint="Internal only. Never shown to the client." className="sm:col-span-2">
            <Textarea rows={2} {...f.bind('admin_notes')} />
          </Field>
        )}
      </div>
    </Modal>
  );
}

/* ---------- Client portal controls (owner/admin) ---------- */
export const portalStatus: Record<string, { label: string; tone: string; text: string }> = {
  not_activated: { label: 'Not activated', tone: 'amber', text: 'Generate a PIN to activate the portal. Until then the client sees “not activated yet”.' },
  active: { label: 'Active', tone: 'green', text: 'The client can open the link and sign in with the PIN.' },
  disabled: { label: 'Disabled', tone: 'gray', text: 'The link shows “turned off”. Turn it on again anytime; the PIN stays the same.' },
};

export function PortalCard({ booking, initialPin, onPinShown, compact }: { booking: any; initialPin?: string | null; onPinShown?: () => void; compact?: boolean }) {
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['portal', booking.id], queryFn: () => api.get(`bookings/${booking.id}/portal`), initialData: booking.portal || undefined });
  const p = q.data;
  const [pin, setPin] = useState<string | null>(initialPin || null);
  const [fresh, setFresh] = useState(!!initialPin);
  const [custom, setCustom] = useState<string | null>(null);
  const [customErr, setCustomErr] = useState('');
  const [busy, setBusy] = useState('');
  const [share, setShare] = useState<{ template: string; pin?: string } | null>(null);
  useEffect(() => {
    if (initialPin) (setPin(initialPin), setFresh(true));
  }, [initialPin]);
  const refresh = () => {
    q.refetch();
    qc.invalidateQueries({ queryKey: ['booking', String(booking.id)] });
    qc.invalidateQueries({ queryKey: ['portals'] });
  };
  const run = async (what: string, fn: () => Promise<any>) => {
    setBusy(what);
    try {
      await fn();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
  };
  const genPin = (value?: string) =>
    run('pin', async () => {
      const r = await api.post(`bookings/${booking.id}/portal/pin`, value ? { pin: value } : {});
      setPin(r.pin);
      setFresh(true);
      setCustom(null);
      toast(p?.has_pin ? 'New PIN created. The old PIN no longer works.' : 'PIN created. The portal is now active.');
      refresh();
    });
  const showPin = () =>
    run('show', async () => {
      const r = await api.get(`bookings/${booking.id}/portal/pin`);
      setPin(r.pin);
      setFresh(false);
    });
  const saveCustom = () => {
    const v = (custom || '').trim();
    if (!/^\d{4,8}$/.test(v)) return setCustomErr('Use 4 to 8 digits.');
    setCustomErr('');
    genPin(v);
  };
  const resetPin = async () => {
    if (!(await confirm({ title: 'Regenerate the portal PIN?', message: 'A new PIN is created. The old PIN stops working and anyone signed in with it is signed out. Share the new PIN with the client.', confirmLabel: 'Regenerate PIN' }))) return;
    genPin();
  };
  const toggle = async (on: boolean) => {
    if (!on && !(await confirm({ title: 'Disable this portal?', message: 'The client will see “This portal is currently turned off” until you enable it again. The PIN stays the same.', confirmLabel: 'Disable portal', danger: true }))) return;
    run('toggle', () => api.post(`bookings/${booking.id}/portal/enabled`, { enabled: on }).then(() => (refresh(), toast(on ? 'Portal enabled' : 'Portal disabled'))));
  };
  const newLink = async () => {
    if (!(await confirm({ title: 'Create a new portal link?', message: 'The current link stops working right away. Use this if the link was shared with the wrong person.', confirmLabel: 'Create new link', danger: true }))) return;
    run('link', () => api.post(`bookings/${booking.id}/portal/new-link`, {}).then(() => (refresh(), toast('New link created. Share it with the client.'))));
  };
  const copy = (text: string, label: string) => navigator.clipboard.writeText(text).then(() => toast(`${label} copied`), () => toast('Could not copy', 'error'));
  if (q.isError && !p) return <div className="rounded-xl border border-line p-4 text-sm text-red-600">Could not load the client portal. {(q.error as Error).message}</div>;
  if (!p) return <div className="rounded-xl border border-line p-4 text-sm text-muted">Loading client portal…</div>;
  const st = portalStatus[p.status || (p.has_pin ? (p.enabled ? 'active' : 'disabled') : 'not_activated')];
  const status = p.status || (p.has_pin ? (p.enabled ? 'active' : 'disabled') : 'not_activated');
  return (
    <div className={clsx('rounded-xl border p-4', status === 'not_activated' ? 'border-amber-300/70 bg-amber-50/40 dark:border-amber-500/30 dark:bg-amber-500/5' : 'border-line')}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-faint"><ShieldCheck className="h-3.5 w-3.5 text-accent-ink" /> Client portal</p>
        <div className="flex items-center gap-2">
          <Badge tone={st.tone}>{st.label}</Badge>
          {p.has_pin && <Toggle checked={p.enabled} onChange={toggle} label={<span className="text-xs text-muted">{p.enabled ? 'On' : 'Off'}</span>} />}
        </div>
      </div>
      <p className="mt-1 text-xs text-muted">{st.text}</p>
      <div className="mt-3 flex items-center gap-1.5 rounded-lg bg-subtle px-2.5 py-2">
        <Link2 className="h-3.5 w-3.5 shrink-0 text-muted" />
        <span className="min-w-0 flex-1 truncate font-mono text-2xs text-muted" title={p.url}>{p.url}</span>
        <button onClick={() => copy(p.url, 'Portal link')} className="rounded p-1 text-muted hover:bg-surface hover:text-ink" aria-label="Copy portal link" title="Copy link"><Copy className="h-3.5 w-3.5" /></button>
        <a href={p.url} target="_blank" rel="noopener noreferrer" className="rounded p-1 text-muted hover:bg-surface hover:text-ink" aria-label="Open portal" title="Open portal"><ExternalLink className="h-3.5 w-3.5" /></a>
      </div>

      {pin ? (
        <div className="mt-3 rounded-lg border border-accent/30 bg-accent-soft/60 p-3">
          <p className="text-xs text-accent-ink">{fresh ? 'New PIN' : 'Current PIN'} · only owners and admins can see this</p>
          <div className="mt-1 flex items-center gap-2">
            <span className="font-mono text-2xl font-semibold tracking-[0.3em]" data-testid="portal-pin">{pin}</span>
            <button onClick={() => copy(pin, 'PIN')} className="rounded p-1 text-muted hover:text-ink" aria-label="Copy PIN" title="Copy PIN"><Copy className="h-4 w-4" /></button>
          </div>
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Button size="xs" variant="success" onClick={() => setShare({ template: 'portal_share', pin })}>Share link + PIN on WhatsApp</Button>
            <Button size="xs" onClick={() => copy(`${p.url}\nPIN: ${pin}`, 'Link and PIN')}>Copy link + PIN</Button>
            <Button size="xs" variant="ghost" onClick={() => (setPin(null), setFresh(false), onPinShown?.())}>Hide</Button>
          </div>
        </div>
      ) : (
        p.has_pin && (
          <div className="mt-3 space-y-1.5 text-xs">
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted">PIN</span>
              <span className="flex items-center gap-2">
                <span className="flex items-center gap-1"><Lock className="h-3 w-3 text-emerald-600" /><span className="font-mono tracking-widest">••••••</span></span>
                {p.pin_viewable ? (
                  <Button size="xs" variant="ghost" icon={<Eye className="h-3.5 w-3.5" />} loading={busy === 'show'} onClick={showPin}>View PIN</Button>
                ) : (
                  <span className="text-2xs text-faint" title="This PIN was created before PINs could be viewed again. Regenerate it to view and share it.">Regenerate to view</span>
                )}
              </span>
            </div>
            <p className="flex justify-between"><span className="text-muted">PIN set</span><span>{p.pin_set_at ? fmtDateTime(p.pin_set_at) : '—'}</span></p>
            {!compact && <p className="flex justify-between"><span className="text-muted">Client views</span><span>{p.view_count}{p.last_viewed_at ? ` · last ${fmtDateTime(p.last_viewed_at)}` : ''}</span></p>}
            {p.locked && (
              <div className="flex items-center justify-between rounded-md bg-red-50 px-2 py-1.5 text-red-700 dark:bg-red-500/10 dark:text-red-300">
                <span>Locked {p.locked_minutes} min after {p.failed_attempts} wrong PINs</span>
                <Button size="xs" variant="ghost" icon={<Unlock className="h-3 w-3" />} loading={busy === 'unlock'} onClick={() => run('unlock', () => api.post(`bookings/${booking.id}/portal/unlock`, {}).then(refresh))}>Unlock</Button>
              </div>
            )}
          </div>
        )
      )}

      {custom !== null && (
        <div className="mt-3 flex items-start gap-1.5">
          <Field error={customErr} className="flex-1"><Input value={custom} onChange={(e) => setCustom(e.target.value.replace(/\D/g, '').slice(0, 8))} inputMode="numeric" placeholder="4–8 digits" autoFocus className="py-1 text-sm" aria-label="Custom PIN" /></Field>
          <Button size="sm" variant="primary" loading={busy === 'pin'} onClick={saveCustom}>Save</Button>
          <Button size="sm" variant="ghost" onClick={() => (setCustom(null), setCustomErr(''))}>Cancel</Button>
        </div>
      )}
      {custom === null && (
        <div className="mt-3 flex flex-wrap gap-1.5">
          {p.has_pin ? (
            <Button size="xs" icon={<KeyRound className="h-3.5 w-3.5" />} loading={busy === 'pin'} onClick={resetPin}>Regenerate PIN</Button>
          ) : (
            <Button size="sm" variant="primary" icon={<KeyRound className="h-4 w-4" />} loading={busy === 'pin'} onClick={() => genPin()}>Generate PIN</Button>
          )}
          <Button size={p.has_pin ? 'xs' : 'sm'} onClick={() => setCustom('')}>{p.has_pin ? 'Change PIN' : 'Set custom PIN'}</Button>
          {p.has_pin && <Button size="xs" onClick={() => setShare({ template: 'portal_share' })}>Share on WhatsApp</Button>}
          {p.has_pin && !compact && <Button size="xs" variant="ghost" icon={<RefreshCw className="h-3.5 w-3.5" />} loading={busy === 'link'} onClick={newLink}>New link</Button>}
        </div>
      )}
      <WhatsAppModal open={!!share} onClose={() => setShare(null)} ctx={{ booking_id: booking.id, ...(share?.pin ? { portal_pin: share.pin } : {}) }} template={share?.template} title="Share client portal" />
    </div>
  );
}

/* ---------- Payment history (audit log) ---------- */
export function PaymentHistoryModal({ paymentId, onClose }: { paymentId: number | null; onClose: () => void }) {
  const q = useQuery({ queryKey: ['payment', paymentId], queryFn: () => api.get(`payments/${paymentId}`), enabled: !!paymentId });
  const p = q.data;
  return (
    <Modal open={!!paymentId} onClose={onClose} title={p ? `History of ${p.receipt_no}` : 'Payment history'} subtitle={p ? `${p.client_name} · ${p.booking_number}` : undefined}>
      {!p ? (
        <p className="py-6 text-center text-sm text-muted">Loading…</p>
      ) : !p.logs.length ? (
        <p className="py-6 text-center text-sm text-muted">No changes recorded yet. Payments recorded before v0.4.0 have no history.</p>
      ) : (
        <LogList items={p.logs} />
      )}
    </Modal>
  );
}

export function LogList({ items, showBooking }: { items: any[]; showBooking?: boolean }) {
  const tone: Record<string, string> = { created: 'green', updated: 'blue', deleted: 'red' };
  return (
    <ol className="space-y-3">
      {items.map((l) => (
        <li key={l.id} className="rounded-lg border border-line p-3">
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <Badge tone={tone[l.action] || 'gray'}>{l.action === 'created' ? 'Created' : l.action === 'updated' ? 'Edited' : 'Deleted'}</Badge>
            <span className="font-medium">{l.user_name || 'System'}</span>
            <span className="text-muted">{fmtDateTime(l.created_at)}</span>
            {showBooking && l.booking_number && <span className="text-muted">· {l.client_name} · {l.booking_number}</span>}
          </div>
          {l.changes ? (
            <ul className="mt-2 space-y-0.5 text-sm">
              {Object.values(l.changes).map((c: any, i) => (
                <li key={i}><span className="text-muted">{c.label}:</span> <span className="line-through decoration-red-400/70">{c.from || '—'}</span> → <b className="font-medium">{c.to || '—'}</b></li>
              ))}
            </ul>
          ) : (
            <p className="mt-1.5 text-sm">{l.summary}</p>
          )}
        </li>
      ))}
    </ol>
  );
}
