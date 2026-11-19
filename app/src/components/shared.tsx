import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Briefcase, CalendarClock, CheckSquare, CreditCard, FileText, MessageCircle, MessageSquare, Phone, Plus, Send, Shuffle, Upload, UserPlus, Users, Wallet, Paperclip } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDateTime, fromNow, nowLocal, telLink } from '@/lib/format';
import { Button, Field, Input, Modal, Select, Textarea, useUi, EmptyState } from './ui';

/* ---------- Activity timeline ---------- */
const actIcon: Record<string, [ReactNode, string]> = {
  created: [<UserPlus />, 'bg-blue-50 text-blue-600 dark:bg-blue-500/15'],
  stage: [<Shuffle />, 'bg-violet-50 text-violet-600 dark:bg-violet-500/15'],
  call: [<Phone />, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15'],
  followup: [<CalendarClock />, 'bg-amber-50 text-amber-600 dark:bg-amber-500/15'],
  note: [<MessageSquare />, 'bg-slate-100 text-slate-600 dark:bg-slate-700/40 dark:text-slate-300'],
  quotation: [<FileText />, 'bg-orange-50 text-orange-600 dark:bg-orange-500/15'],
  booking: [<Briefcase />, 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15'],
  payment: [<CreditCard />, 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15'],
  whatsapp: [<MessageCircle />, 'bg-green-50 text-green-600 dark:bg-green-500/15'],
  task: [<CheckSquare />, 'bg-cyan-50 text-cyan-600 dark:bg-cyan-500/15'],
  team: [<Users />, 'bg-pink-50 text-pink-600 dark:bg-pink-500/15'],
  document: [<Paperclip />, 'bg-slate-100 text-slate-600 dark:bg-slate-700/40'],
  expense: [<Wallet />, 'bg-red-50 text-red-600 dark:bg-red-500/15'],
  assigned: [<Users />, 'bg-pink-50 text-pink-600 dark:bg-pink-500/15'],
};

export function Timeline({ items, empty = 'No activity yet.' }: { items: any[]; empty?: string }) {
  if (!items?.length) return <EmptyState title={empty} className="py-8" />;
  return (
    <ol className="relative space-y-4">
      {items.map((a, i) => {
        const [icon, cls] = actIcon[a.type] || actIcon.note;
        return (
          <li key={a.id} className="relative flex gap-3">
            {i < items.length - 1 && <span className="absolute left-[15px] top-8 h-[calc(100%-8px)] w-px bg-line" />}
            <span className={clsx('relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full [&>svg]:h-3.5 [&>svg]:w-3.5', cls)}>{icon}</span>
            <div className="min-w-0 flex-1 pt-1">
              <p className="whitespace-pre-line break-words text-sm">{a.message}</p>
              <p className="mt-0.5 text-xs text-faint" title={fmtDateTime(a.created_at)}>
                {a.user_name ? a.user_name + ' · ' : ''}
                {fmtDateTime(a.created_at)} · {fromNow(a.created_at)}
              </p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

export function NoteBox({ onSave, placeholder = 'Add a note…' }: { onSave: (msg: string) => Promise<any>; placeholder?: string }) {
  const [v, setV] = useState('');
  const [busy, setBusy] = useState(false);
  const { toast } = useUi();
  const save = async () => {
    if (!v.trim()) return;
    setBusy(true);
    try {
      await onSave(v.trim());
      setV('');
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  return (
    <div className="flex gap-2">
      <Textarea value={v} onChange={(e) => setV(e.target.value)} placeholder={placeholder} rows={2} className="min-h-0" onKeyDown={(e) => (e.metaKey || e.ctrlKey) && e.key === 'Enter' && save()} />
      <Button variant="primary" onClick={save} loading={busy} disabled={!v.trim()} className="self-end" icon={<Send className="h-4 w-4" />}>
        <span className="sr-only">Save note</span>
      </Button>
    </div>
  );
}

/* ---------- Call logging ---------- */
export function LogCallModal({ lead, open, onClose, onDone }: { lead: { id: number; name: string; phone?: string | null } | null; open: boolean; onClose: () => void; onDone?: () => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const [status, setStatus] = useState('connected');
  const [notes, setNotes] = useState('');
  const [calledAt, setCalledAt] = useState(nowLocal());
  const [next, setNext] = useState('');
  useEffect(() => {
    if (open) {
      setStatus('connected');
      setNotes('');
      setCalledAt(nowLocal());
      setNext('');
    }
  }, [open]);
  const quick = (days: number, hour = 11) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    d.setHours(hour, 0, 0, 0);
    const p = (n: number) => String(n).padStart(2, '0');
    setNext(`${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:00`);
  };
  const m = useMutation({
    mutationFn: () => api.post(`leads/${lead!.id}/calls`, { status, notes, called_at: calledAt, next_call_at: next || null }),
    onSuccess: () => {
      toast('Call logged');
      ['lead', 'leads', 'calling', 'calling-summary', 'followups', 'pipeline', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onDone?.();
      onClose();
    },
    onError: (e: any) => toast(e.message, 'error'),
  });
  const needsNext = ['call_back', 'followup', 'not_answered', 'attempted', 'switched_off', 'interested', 'connected'].includes(status);
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Log call"
      subtitle={lead?.name}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={m.isPending} onClick={() => m.mutate()}>
            Save call
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {lead?.phone && (
          <a href={telLink(lead.phone)} className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-line py-2.5 text-sm font-medium text-accent-ink hover:bg-accent-soft">
            <Phone className="h-4 w-4" /> Call {lead.phone}
          </a>
        )}
        <Field label="Call status" required>
          <div className="grid grid-cols-2 gap-1.5">
            {Object.entries(config.call_statuses)
              .filter(([k]) => k !== 'not_called')
              .map(([k, v]) => (
                <button key={k} type="button" onClick={() => setStatus(k)} className={clsx('rounded-lg border px-2.5 py-2 text-left text-xs font-medium transition-colors', status === k ? 'border-accent bg-accent-soft text-accent-ink' : 'border-line hover:bg-subtle')}>
                  {v}
                </button>
              ))}
          </div>
        </Field>
        <Field label="Calling notes">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} placeholder="What did the client say?" />
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Call date & time">
            <Input type="datetime-local" value={calledAt} onChange={(e) => setCalledAt(e.target.value)} max={nowLocal()} />
          </Field>
          <Field label="Next call / follow-up" hint={needsNext && !next ? 'Recommended for this status.' : undefined}>
            <Input type="datetime-local" value={next} onChange={(e) => setNext(e.target.value)} min={calledAt} />
          </Field>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {[
            ['In 2 hours', () => { const d = new Date(Date.now() + 2 * 3600e3); const p = (n: number) => String(n).padStart(2, '0'); setNext(`${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`); }],
            ['Tomorrow', () => quick(1)],
            ['In 3 days', () => quick(3)],
            ['Next week', () => quick(7)],
          ].map(([l, fn]) => (
            <Button key={l as string} size="xs" type="button" onClick={fn as () => void}>
              {l as string}
            </Button>
          ))}
        </div>
      </div>
    </Modal>
  );
}

/* ---------- Follow-up scheduling ---------- */
export function FollowupModal({ leadId, open, onClose, followup }: { leadId: number; open: boolean; onClose: () => void; followup?: any }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [due, setDue] = useState('');
  const [note, setNote] = useState('');
  useEffect(() => {
    if (open) {
      setDue(followup ? followup.due_at.replace(' ', 'T').slice(0, 16) : '');
      setNote(followup?.note || '');
    }
  }, [open, followup]);
  const m = useMutation({
    mutationFn: () => (followup ? api.put(`followups/${followup.id}`, { due_at: due, note }) : api.post('followups', { lead_id: leadId, due_at: due, note })),
    onSuccess: () => {
      toast(followup ? 'Follow-up rescheduled' : 'Follow-up scheduled');
      ['lead', 'followups', 'calling', 'calling-summary', 'dashboard', 'leads'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onClose();
    },
    onError: (e: any) => toast(e.message, 'error'),
  });
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={followup ? 'Reschedule follow-up' : 'Schedule follow-up'}
      size="sm"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" disabled={!due} loading={m.isPending} onClick={() => m.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Date & time" required>
          <Input type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} />
        </Field>
        <Field label="Note">
          <Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder="e.g. Share album samples" />
        </Field>
      </div>
    </Modal>
  );
}

/* ---------- WhatsApp ---------- */
export type WaContext = { lead_id?: number; client_id?: number; booking_id?: number; quotation_id?: number; event_id?: number; deliverable_id?: number; portal_pin?: string };

export function WhatsAppModal({ open, onClose, ctx, template: initialTemplate, title = 'Send WhatsApp' }: { open: boolean; onClose: () => void; ctx: WaContext; template?: string; title?: string }) {
  const { toast } = useUi();
  const { can } = useAuth();
  const qc = useQueryClient();
  const templates = useQuery({ queryKey: ['wa-templates'], queryFn: () => api.get<any[]>('whatsapp/templates'), enabled: open });
  const [tpl, setTpl] = useState(initialTemplate || 'followup');
  const [text, setText] = useState('');
  const [phone, setPhone] = useState('');
  const [api_, setApi] = useState(false);
  const [loading, setLoading] = useState(false);
  const [sending, setSending] = useState(false);
  const [locked, setLocked] = useState('');

  useEffect(() => {
    if (open && initialTemplate) setTpl(initialTemplate);
  }, [open, initialTemplate]);

  useEffect(() => {
    if (!open) return;
    let off = false;
    setLoading(true);
    const t = templates.data?.find((x) => x.slug === tpl);
    api
      .post('whatsapp/render', { ...ctx, ...(t ? { template_id: t.id } : { template: tpl }) })
      .then((r) => {
        if (off) return;
        setText(r.text);
        setPhone(r.phone || '');
        setApi(!!r.api_configured);
      })
      .then(() => !off && setLocked(''))
      .catch((e) => {
        if (off) return;
        if (e.code === 'plan_feature' || e.code === 'plan_expired') {
          setLocked(e.message);
          setText('');
        } else toast(e.message, 'error');
      })
      .finally(() => !off && setLoading(false));
    return () => {
      off = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, tpl, templates.data, JSON.stringify(ctx)]);

  const refresh = () => ['lead', 'client', 'booking', 'quotation', 'quotations'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
  const openChat = () => {
    if (!phone) return toast('No phone number for this contact.', 'error');
    let d = phone.replace(/\D/g, '');
    if (d.length === 10) d = '91' + d;
    window.open(`https://wa.me/${d}?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
    api.post('whatsapp/log', { ...ctx, text }).then(refresh).catch(() => undefined);
    onClose();
  };
  const sendApi = async () => {
    setSending(true);
    try {
      await api.post('whatsapp/send', { ...ctx, phone, text });
      toast('Message sent via WhatsApp Business API');
      refresh();
      onClose();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setSending(false);
  };
  const active = (templates.data || []).filter((t) => t.is_active);
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      subtitle="Choose a template, edit if needed, then open WhatsApp."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          {api_ && can('whatsapp.send') && (
            <Button onClick={sendApi} loading={sending} disabled={!text || !phone}>
              Send via API
            </Button>
          )}
          <Button variant="success" onClick={openChat} disabled={!text || !phone || loading} icon={<MessageCircle className="h-4 w-4" />}>
            Open WhatsApp
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {locked && (
          <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
            {locked}{' '}
            {can('settings.edit') && <a className="font-semibold underline" href={window.PCRM.base + '/billing'}>See plans</a>}
            <p className="mt-1 text-xs">You can still type your own message and number below.</p>
          </div>
        )}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Template">
            <Select value={tpl} onChange={(e) => setTpl(e.target.value)} options={active.map((t) => [t.slug, t.name] as [string, string])} />
          </Field>
          <Field label="WhatsApp number">
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" />
          </Field>
        </div>
        <Field label="Message">
          <Textarea value={loading ? 'Preparing message…' : text} onChange={(e) => setText(e.target.value)} rows={8} disabled={loading} />
        </Field>
        {!api_ && <p className="text-xs text-faint">Opens WhatsApp on this device with the message ready to send. Business API sending turns on after you add API credentials in Settings → WhatsApp.</p>}
      </div>
    </Modal>
  );
}

export function WaButton({ ctx, template, label = 'WhatsApp', size = 'sm', variant = 'secondary' }: { ctx: WaContext; template?: string; label?: string; size?: 'xs' | 'sm' | 'md'; variant?: 'secondary' | 'ghost' | 'success' }) {
  const [open, setOpen] = useState(false);
  const { can } = useAuth();
  if (!can('whatsapp.send')) return null;
  return (
    <>
      <Button size={size} variant={variant} onClick={() => setOpen(true)} icon={<MessageCircle className="h-4 w-4 text-green-600" />}>
        {label}
      </Button>
      <WhatsAppModal open={open} onClose={() => setOpen(false)} ctx={ctx} template={template} />
    </>
  );
}

/* ---------- Document uploads ---------- */
export function Documents({ docs, clientId, bookingId, onChange }: { docs: any[]; clientId?: number; bookingId?: number; onChange: () => void }) {
  const { toast, confirm } = useUi();
  const [busy, setBusy] = useState(false);
  const upload = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    if (clientId) fd.append('client_id', String(clientId));
    if (bookingId) fd.append('booking_id', String(bookingId));
    setBusy(true);
    try {
      await api.upload('documents', fd);
      toast('File uploaded');
      onChange();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  const remove = async (d: any) => {
    if (!(await confirm({ title: 'Delete file?', message: d.name, danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`documents/${d.id}`);
      onChange();
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  return (
    <div>
      <label className={clsx('flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-line px-4 py-5 text-sm text-muted hover:bg-subtle', busy && 'pointer-events-none opacity-60')}>
        <Upload className="h-4 w-4" /> {busy ? 'Uploading…' : 'Upload contract, ID, shot list… (max 15 MB)'}
        <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
      </label>
      {docs?.length > 0 && (
        <ul className="mt-3 divide-y divide-line rounded-lg border border-line">
          {docs.map((d) => (
            <li key={d.id} className="flex items-center gap-3 px-3 py-2.5 text-sm">
              <Paperclip className="h-4 w-4 shrink-0 text-faint" />
              <a href={d.url} target="_blank" rel="noreferrer" className="min-w-0 flex-1 truncate font-medium hover:text-accent-ink">
                {d.name}
              </a>
              <span className="hidden text-xs text-faint sm:inline">{d.size ? (d.size / 1024 / 1024).toFixed(1) + ' MB' : ''}</span>
              <button onClick={() => remove(d)} className="text-xs text-red-600 hover:underline">
                Delete
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function QuickAdd({ onClick, label }: { onClick: () => void; label: string }) {
  return (
    <Button size="sm" variant="ghost" onClick={onClick} icon={<Plus className="h-4 w-4" />}>
      {label}
    </Button>
  );
}

export function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2 text-sm">
      <span className="shrink-0 text-muted">{label}</span>
      <span className="min-w-0 text-right font-medium">{children || '—'}</span>
    </div>
  );
}
