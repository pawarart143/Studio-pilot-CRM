import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Camera, MessageCircle } from 'lucide-react';
import { api, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useForm, validate, clean } from '@/lib/form';
import { fmtDateLong, fmtTime, waLink } from '@/lib/format';
import type { Tone } from '@/components/ui';
import { Avatar, Button, Field, Input, Modal, Select, Textarea, useUi } from '@/components/ui';

export const crewStatusTone: Record<string, Tone> = {
  scheduled: 'blue',
  confirmation_pending: 'amber',
  confirmed: 'green',
  declined: 'red',
  reassigned: 'violet',
  completed: 'indigo',
  cancelled: 'gray',
};
export const crewAvailTone: Record<string, Tone> = { available: 'green', limited: 'amber', unavailable: 'gray', inactive: 'gray' };
export const crewPayTone: Record<string, Tone> = { pending: 'gray', partial: 'amber', paid: 'green' };
export const INACTIVE = ['declined', 'reassigned', 'cancelled'];

export type CrewMember = {
  id: number;
  name: string;
  phone: string;
  whatsapp?: string | null;
  email?: string | null;
  photo_url?: string | null;
  category: string;
  specialization?: string | null;
  city?: string | null;
  daily_rate?: number | null;
  availability: string;
  notes?: string | null;
  total_assignments?: number;
  upcoming?: number;
};

export function useCrew(enabled = true) {
  return useQuery({ queryKey: ['crew', 'all'], queryFn: () => api.get<{ items: CrewMember[]; cities: string[] }>('crew'), enabled, staleTime: 60_000 });
}

export function crewWa(c: { name?: string | null; phone?: string | null; whatsapp?: string | null; crew_name?: string; crew_phone?: string; crew_whatsapp?: string }, text?: string): string | null {
  const name = c.name || c.crew_name || '';
  const phone = c.whatsapp || c.crew_whatsapp || c.phone || c.crew_phone;
  return (phone && waLink(phone, text ?? `Hi ${name.split(" ")[0]},`)) || null;
}

export function WaIcon({ href, label = 'WhatsApp' }: { href: string | null; label?: string }) {
  if (!href) return null;
  return (
    <a href={href} target="_blank" rel="noreferrer" className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 text-xs font-medium text-emerald-700 shadow-sm hover:bg-subtle dark:text-emerald-300" title="Open WhatsApp">
      <MessageCircle className="h-4 w-4" />
      {label}
    </a>
  );
}

export function timeRange(s: { start_time?: string | null; reporting_time?: string | null; end_time?: string | null }) {
  const parts: string[] = [];
  if (s.reporting_time) parts.push(`Report ${fmtTime(s.reporting_time)}`);
  if (s.start_time) parts.push(fmtTime(s.start_time) + (s.end_time ? ` – ${fmtTime(s.end_time)}` : ''));
  else if (s.end_time) parts.push(`till ${fmtTime(s.end_time)}`);
  return parts.join(' · ') || 'All day';
}

/** Conflict list shown in warnings and in the override confirmation. */
export function ConflictList({ items }: { items: { crew_name?: string; conflicts: any[] }[] }) {
  return (
    <ul className="space-y-2">
      {items.map((c, i) => (
        <li key={i} className="rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
          {c.crew_name && <p className="font-medium">{c.crew_name} is already booked:</p>}
          {c.conflicts.map((x: any) => (
            <p key={x.assignment_id} className="text-xs text-amber-900 dark:text-amber-200">
              {x.event_name}
              {x.client_name ? ` – ${x.client_name}` : ''} · {fmtDateLong(x.event_date)} · {timeRange(x)}
              {x.venue || x.city ? ` · ${[x.venue, x.city].filter(Boolean).join(', ')}` : ''}
            </p>
          ))}
        </li>
      ))}
    </ul>
  );
}

/** Asks for confirmation (and an optional reason) before double-booking crew. */
export function OverrideModal({ open, conflicts, onCancel, onConfirm, busy }: { open: boolean; conflicts: any[]; onCancel: () => void; onConfirm: (note: string) => void; busy?: boolean }) {
  const [note, setNote] = useState('');
  useEffect(() => setNote(''), [open]);
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={
        <span className="inline-flex items-center gap-2">
          <AlertTriangle className="h-4 w-4 text-amber-600" />
          Crew already booked at this time
        </span>
      }
      subtitle="You can still assign them, for example if they will cover both events."
      footer={
        <>
          <Button onClick={onCancel}>Go back</Button>
          <Button variant="primary" loading={busy} onClick={() => onConfirm(note.trim())}>Assign anyway</Button>
        </>
      }
    >
      <div className="space-y-3">
        <ConflictList items={conflicts} />
        <Field label="Reason (optional)" hint="Saved with the assignment so the team knows it was intentional.">
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Covering haldi first, then reaching the sangeet" maxLength={255} />
        </Field>
      </div>
    </Modal>
  );
}

export function CrewModal({ member, onClose, onSaved }: { member: Partial<CrewMember> | null; onClose: () => void; onSaved?: (c: CrewMember) => void }) {
  const { config, can } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const money = can('crew.payments');
  const isNew = !member?.id;
  const blank = { name: '', phone: '', whatsapp: '', email: '', category: 'candid_photographer', specialization: '', city: '', daily_rate: '', availability: 'available', notes: '' };
  const f = useForm<Record<string, any>>(blank);
  const [photo, setPhoto] = useState<File | null>(null);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!member) return;
    const v: Record<string, any> = { ...blank };
    Object.keys(blank).forEach((k) => {
      const val = (member as any)[k];
      if (val !== undefined && val !== null) v[k] = String(val);
    });
    f.setValues(v);
    f.setErrors({});
    setPhoto(null);
    setPhotoUrl(member.photo_url || null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [member]);

  useEffect(() => {
    if (!photo) return setPreview(null);
    const u = URL.createObjectURL(photo);
    setPreview(u);
    return () => URL.revokeObjectURL(u);
  }, [photo]);

  const save = async () => {
    const e = validate(f.values, { name: ['required'], phone: ['required', 'phone'], whatsapp: ['phone'], email: ['email'], category: ['required'], daily_rate: ['positive'] }, { name: 'Full name', phone: 'Phone number', category: 'Crew category', daily_rate: 'Daily rate' });
    if (photo && photo.size > 3 * 1024 * 1024) e.photo = 'Photo must be smaller than 3 MB.';
    if (Object.keys(e).length) return f.setErrors(e);
    setBusy(true);
    try {
      const body = clean({ ...f.values });
      if (!money) delete (body as any).daily_rate;
      const r = isNew ? await api.post<CrewMember>('crew', body) : await api.put<CrewMember>(`crew/${member!.id}`, body);
      if (photo) {
        const fd = new FormData();
        fd.append('file', photo);
        try {
          const p = await api.upload(`crew/${r.id}/photo`, fd);
          r.photo_url = p.photo_url;
        } catch (pe) {
          toast('Saved, but the photo did not upload: ' + errMsg(pe), 'error');
        }
      } else if (!isNew && member?.photo_url && !photoUrl) {
        await api.del(`crew/${r.id}/photo`).catch(() => undefined);
      }
      qc.invalidateQueries({ queryKey: ['crew'] });
      toast(isNew ? `${r.name} added to your crew` : 'Crew member updated');
      onSaved?.(r);
      onClose();
    } catch (err) {
      f.fromError(err);
      toast(errMsg(err), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={!!member}
      onClose={onClose}
      size="lg"
      title={isNew ? 'Add crew member' : `Edit ${member?.name}`}
      subtitle="Freelancers and outside crew. This is separate from your Team."
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>{isNew ? 'Add crew member' : 'Save'}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
          <Avatar name={f.values.name || 'New'} src={preview || photoUrl} size="lg" />
          <div className="flex flex-wrap gap-2">
            <label className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium shadow-sm hover:bg-subtle">
              <Camera className="h-4 w-4" />
              {photo || photoUrl ? 'Change photo' : 'Add photo'}
              <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => setPhoto(e.target.files?.[0] || null)} />
            </label>
            {(photo || photoUrl) && (
              <Button size="sm" variant="ghost" onClick={() => (setPhoto(null), setPhotoUrl(null))}>Remove</Button>
            )}
          </div>
          {f.errors.photo && <p className="text-xs text-red-600">{f.errors.photo}</p>}
        </div>
        <Field label="Full name" required error={f.errors.name}><Input {...f.bind('name')} autoFocus /></Field>
        <Field label="Crew category" required error={f.errors.category}><Select {...f.bind('category')} options={config.crew_categories} /></Field>
        <Field label="Phone number" required error={f.errors.phone}><Input {...f.bind('phone')} inputMode="tel" placeholder="98765 43210" /></Field>
        <Field label="WhatsApp number" error={f.errors.whatsapp} hint="Leave empty if same as phone."><Input {...f.bind('whatsapp')} inputMode="tel" /></Field>
        <Field label="Email" error={f.errors.email}><Input {...f.bind('email')} type="email" /></Field>
        <Field label="City" error={f.errors.city}><Input {...f.bind('city')} placeholder="Pune" /></Field>
        <Field label="Specialization" className="sm:col-span-2" error={f.errors.specialization}><Input {...f.bind('specialization')} placeholder="Candid, FPV drone, Sony FX3, same-day edits…" /></Field>
        {money && (
          <Field label="Daily rate (₹)" error={f.errors.daily_rate} hint="Used as the default rate when you schedule them.">
            <Input {...f.bind('daily_rate')} inputMode="decimal" placeholder="8000" />
          </Field>
        )}
        <Field label="Availability" error={f.errors.availability}><Select {...f.bind('availability')} options={config.crew_availability} /></Field>
        <Field label="Notes" className="sm:col-span-2"><Textarea rows={3} {...f.bind('notes')} placeholder="Gear, languages, travel preferences…" /></Field>
      </div>
    </Modal>
  );
}
