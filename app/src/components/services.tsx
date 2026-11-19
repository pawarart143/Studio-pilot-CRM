import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, Plus, Trash2, X } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTeam } from '@/lib/data';
import { fmtDateShort, fmtTime } from '@/lib/format';
import { Avatar, Button, Input, Select, Toggle } from './ui';

export type ServiceRow = { id?: number; service_id?: number | null; name: string; category?: string; on_site: boolean; notes?: string; team: number[] };

export const serviceFromApi = (s: any): ServiceRow => ({ id: s.id, service_id: s.service_id || null, name: s.name, category: s.category || '', on_site: !!s.on_site, notes: s.notes || '', team: (s.team || []).map((t: any) => Number(t.member_id)) });
export const serviceToApi = (s: ServiceRow) => ({ id: s.id || undefined, service_id: s.service_id || null, name: s.name.trim(), category: s.category || null, on_site: s.on_site ? 1 : 0, notes: s.notes || '', team: s.team });

/** Edit a booking's services and pick team members for each, with busy warnings for the given event dates. */
export function ServicesEditor({ value, onChange, dates, bookingId, errors }: { value: ServiceRow[]; onChange: (v: ServiceRow[]) => void; dates: string[]; bookingId?: number; errors?: Record<number, string> }) {
  const { config } = useAuth();
  const team = useTeam();
  const catalogue = useQuery({ queryKey: ['services', 'active'], queryFn: () => api.get<any[]>('services', { active: 1 }) });
  const sorted = [...new Set(dates.filter(Boolean))].sort();
  const from = sorted[0];
  const to = sorted[sorted.length - 1];
  const avail = useQuery({ queryKey: ['availability', from, to], queryFn: () => api.get<any>('team/availability', { from, to }), enabled: !!from });
  const [pick, setPick] = useState('');
  const [picker, setPicker] = useState<number | null>(null);
  const crew = (team.data || []).filter((m: any) => m.status === 'active');
  const busy = new Map<number, any[]>();
  (avail.data?.members || []).forEach((m: any) => busy.set(m.id, (m.busy || []).filter((e: any) => e.booking_id !== bookingId && sorted.includes(e.event_date))));
  const set = (i: number, patch: Partial<ServiceRow>) => onChange(value.map((s, j) => (j === i ? { ...s, ...patch } : s)));
  const move = (i: number, d: number) => {
    const n = [...value];
    const [x] = n.splice(i, 1);
    n.splice(i + d, 0, x);
    onChange(n);
  };
  const add = () => {
    if (!pick) return;
    if (pick === '__custom') onChange([...value, { name: '', on_site: true, notes: '', team: [] }]);
    else {
      const s = (catalogue.data || []).find((x) => String(x.id) === pick);
      if (s) onChange([...value, { service_id: s.id, name: s.name, category: s.category || '', on_site: !['album', 'editing', 'post_production'].includes(s.category), notes: '', team: [] }]);
    }
    setPick('');
  };
  const options: [string, string][] = [['__custom', '+ Custom service'], ...(catalogue.data || []).map((s: any) => [String(s.id), s.name] as [string, string])];

  return (
    <div className="space-y-3">
      {value.length === 0 && <p className="text-sm text-muted">No services yet. Add Candid Photography, Cinematography, Drone and so on, then pick the team for each.</p>}
      {value.map((s, i) => (
        <div key={i} className="rounded-xl border border-line p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Input value={s.name} onChange={(e) => set(i, { name: e.target.value })} placeholder="Service name" className="min-w-[180px] flex-1" invalid={!!errors?.[i]} aria-label="Service name" />
            <Toggle checked={s.on_site} onChange={(v) => set(i, { on_site: v })} label={<span className="text-xs">On-site</span>} />
            <div className="flex">
              <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="rounded p-1.5 text-faint hover:text-ink disabled:opacity-30" aria-label="Move up"><ArrowUp className="h-4 w-4" /></button>
              <button type="button" disabled={i === value.length - 1} onClick={() => move(i, 1)} className="rounded p-1.5 text-faint hover:text-ink disabled:opacity-30" aria-label="Move down"><ArrowDown className="h-4 w-4" /></button>
              <button type="button" onClick={() => onChange(value.filter((_, j) => j !== i))} className="rounded p-1.5 text-faint hover:text-red-600" aria-label="Remove service"><Trash2 className="h-4 w-4" /></button>
            </div>
          </div>
          {errors?.[i] && <p className="mt-1 text-xs text-red-600">{errors[i]}</p>}
          <div className="mt-2 flex flex-wrap items-center gap-1.5">
            {s.team.map((mid) => {
              const m = crew.find((x: any) => x.id === mid) || (team.data || []).find((x: any) => x.id === mid);
              const b = s.on_site ? busy.get(mid) || [] : [];
              return (
                <span key={mid} className={clsx('inline-flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-1.5 text-xs', b.length ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-300 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/40' : 'bg-subtle')} title={b.length ? 'Busy: ' + b.map((e: any) => `${e.name} (${e.booking_title}) ${fmtDateShort(e.event_date)}`).join(', ') : undefined}>
                  <Avatar name={m?.name} color={m?.color} src={m?.photo_url} size="xs" />
                  {m?.name || 'Member #' + mid}
                  {b.length > 0 && <span className="font-medium">· busy</span>}
                  <button type="button" onClick={() => set(i, { team: s.team.filter((x) => x !== mid) })} className="rounded-full p-0.5 hover:bg-black/10" aria-label={`Remove ${m?.name}`}><X className="h-3 w-3" /></button>
                </span>
              );
            })}
            <button type="button" onClick={() => setPicker(picker === i ? null : i)} className="inline-flex items-center gap-1 rounded-full border border-dashed border-line px-2.5 py-0.5 text-xs text-muted hover:border-accent hover:text-accent-ink"><Plus className="h-3 w-3" /> Team member</button>
          </div>
          {picker === i && (
            <ul className="scrollbar-thin mt-2 max-h-60 divide-y divide-line overflow-y-auto rounded-lg border border-line">
              {crew.length === 0 && <li className="px-3 py-2 text-xs text-muted">No active team members. Add them on the Team page.</li>}
              {crew.map((m: any) => {
                const on = s.team.includes(m.id);
                const b = busy.get(m.id) || [];
                return (
                  <li key={m.id}>
                    <label className="flex cursor-pointer items-center gap-2.5 px-3 py-2 hover:bg-subtle/60">
                      <input type="checkbox" checked={on} onChange={() => set(i, { team: on ? s.team.filter((x) => x !== m.id) : [...s.team, m.id] })} className="h-4 w-4 rounded border-line text-accent-ink focus:ring-accent" />
                      <Avatar name={m.name} color={m.color} src={m.photo_url} size="sm" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium">{m.name}</span>
                        <span className={clsx('block truncate text-xs', b.length && s.on_site ? 'text-amber-700 dark:text-amber-300' : 'text-muted')}>
                          {m.team_role ? config.team_roles[m.team_role] : config.roles[m.role]}
                          {m.availability && m.availability !== 'available' ? ` · ${config.availability[m.availability]}` : ''}
                          {b.length && s.on_site ? ` · Busy: ${b.map((e: any) => `${e.name} ${fmtDateShort(e.event_date)}${e.start_time ? ' ' + fmtTime(e.start_time) : ''}`).join(', ')}` : ''}
                        </span>
                      </span>
                    </label>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      ))}
      <div className="flex gap-2">
        <Select value={pick} onChange={(e) => setPick(e.target.value)} options={options} placeholder="Choose a service…" className="flex-1" aria-label="Add service" />
        <Button type="button" icon={<Plus className="h-4 w-4" />} onClick={add} disabled={!pick}>Add</Button>
      </div>
      {!from && value.some((s) => s.team.length) && <p className="text-xs text-muted">Add event dates to check team availability.</p>}
    </div>
  );
}

export function validateServices(rows: ServiceRow[]) {
  const e: Record<number, string> = {};
  rows.forEach((s, i) => {
    if (!s.name.trim()) e[i] = 'Give this service a name.';
  });
  return e;
}
