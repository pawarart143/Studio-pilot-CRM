import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, BedDouble, Briefcase, CalendarDays, Clock, Copy, MapPin, Pencil, Phone, Repeat, Trash2, Truck } from 'lucide-react';
import { api, ApiError, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDateLong, fmtDateTime, inr, phoneDisplay, telLink } from '@/lib/format';
import { Avatar, Badge, Button, Drawer, ErrorState, Field, Input, Modal, PageLoader, Select, useUi } from '@/components/ui';
import { crewStatusTone, INACTIVE, OverrideModal, timeRange, useCrew, WaIcon } from './shared';

export default function ScheduleDetail({ id, onClose, onEdit, onCopy }: { id: number | null; onClose: () => void; onEdit: (s: any) => void; onCopy: (s: any) => void }) {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['crew-schedule', id], queryFn: () => api.get(`crew-schedules/${id}`), enabled: !!id });
  const s = q.data;
  const edit = can('crew.edit');
  const money = can('crew.payments');
  const [pending, setPending] = useState<{ kind: 'status' | 'reassign'; aid: number; body: any; conflicts: any[] } | null>(null);
  const [reassign, setReassign] = useState<any>(null);
  const [busy, setBusy] = useState(false);

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['crew-schedules'] });
    qc.invalidateQueries({ queryKey: ['crew-schedule', id] });
    qc.invalidateQueries({ queryKey: ['crew'] });
    qc.invalidateQueries({ queryKey: ['crew-payments'] });
  };

  const send = async (kind: 'status' | 'reassign', aid: number, body: any) => {
    setBusy(true);
    try {
      const r = await api.post(`crew-assignments/${aid}/${kind}`, body);
      qc.setQueryData(['crew-schedule', id], r);
      refresh();
      setPending(null);
      setReassign(null);
      toast(kind === 'status' ? 'Status updated' : 'Crew member reassigned');
    } catch (e) {
      if (e instanceof ApiError && e.code === 'crew_conflict') setPending({ kind, aid, body, conflicts: e.data.conflicts || [] });
      else toast(errMsg(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!(await confirm({ title: 'Delete this schedule?', message: 'All crew assignments for this event are removed. Payment records without payments are removed too.', confirmLabel: 'Delete', danger: true }))) return;
    try {
      await api.del(`crew-schedules/${id}`);
      refresh();
      toast('Schedule deleted');
      onClose();
    } catch (e) {
      toast(errMsg(e), 'error');
    }
  };

  const active = (s?.assignments || []).filter((a: any) => !INACTIVE.includes(a.status));
  const inactive = (s?.assignments || []).filter((a: any) => INACTIVE.includes(a.status));

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-2xl"
        title={s ? s.event_name : 'Crew schedule'}
        footer={
          s &&
          edit && (
            <>
              <Button variant="ghost" icon={<Trash2 className="h-4 w-4" />} onClick={remove}>Delete</Button>
              <Button icon={<Copy className="h-4 w-4" />} onClick={() => onCopy(s)}>Schedule another day</Button>
              <Button variant="primary" icon={<Pencil className="h-4 w-4" />} onClick={() => onEdit(s)}>Edit</Button>
            </>
          )
        }
      >
        {q.isLoading ? (
          <PageLoader />
        ) : q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : s ? (
          <div className="space-y-5 p-5">
            <div className="space-y-1.5 text-sm">
              {s.client_name && <p className="text-base font-medium">{s.client_name}</p>}
              <p className="flex items-center gap-2 text-muted"><CalendarDays className="h-4 w-4" />{fmtDateLong(s.event_date)}{s.event_type && <Badge>{config.event_types[s.event_type] || s.event_type}</Badge>}</p>
              <p className="flex items-center gap-2 text-muted"><Clock className="h-4 w-4" />{timeRange(s)}</p>
              {(s.venue || s.city) && <p className="flex items-center gap-2 text-muted"><MapPin className="h-4 w-4" />{[s.venue, s.city].filter(Boolean).join(', ')}</p>}
              {s.booking_id && (
                <p className="flex items-center gap-2 text-muted">
                  <Briefcase className="h-4 w-4" />
                  {can('bookings.view') ? <Link className="hover:text-accent-ink" to={`/bookings/${s.booking_id}`}>{s.booking_number} · {s.booking_title}</Link> : `${s.booking_number} · ${s.booking_title}`}
                </p>
              )}
            </div>
            {(s.transport || s.accommodation || s.instructions) && (
              <div className="space-y-2 rounded-xl bg-subtle/60 p-3 text-sm">
                {s.transport && <p className="flex gap-2"><Truck className="mt-0.5 h-4 w-4 shrink-0 text-muted" /><span className="whitespace-pre-line">{s.transport}</span></p>}
                {s.accommodation && <p className="flex gap-2"><BedDouble className="mt-0.5 h-4 w-4 shrink-0 text-muted" /><span className="whitespace-pre-line">{s.accommodation}</span></p>}
                {s.instructions && <p className="whitespace-pre-line"><span className="font-medium">Instructions: </span>{s.instructions}</p>}
              </div>
            )}

            <section>
              <h3 className="mb-2 text-sm font-semibold">Crew ({active.length})</h3>
              {!active.length && <p className="text-sm text-muted">No active crew on this event.</p>}
              <ul className="space-y-2">
                {[...active, ...inactive].map((a: any) => (
                  <li key={a.id} className={'rounded-xl border border-line p-3 ' + (INACTIVE.includes(a.status) ? 'opacity-60' : '')} data-testid="assignment">
                    <div className="flex flex-wrap items-start gap-3">
                      <Avatar name={a.crew_name} src={a.crew_photo} size="md" />
                      <div className="min-w-0 flex-1">
                        <Link to={`/crew/members/${a.crew_member_id}`} className="font-medium hover:text-accent-ink">{a.crew_name}</Link>
                        {a.crew_deleted && <span className="ml-1 text-xs text-muted">(removed)</span>}
                        <p className="text-xs text-muted">
                          {config.crew_categories[a.role] || a.role}
                          {a.service ? ` · ${a.service}` : ''}
                          {money && a.rate != null ? ` · ${inr(a.rate)}` : ''}
                        </p>
                        {a.crew_phone && <a href={telLink(a.crew_phone)} className="text-xs text-muted hover:text-accent-ink"><Phone className="mr-1 inline h-3 w-3" />{phoneDisplay(a.crew_phone)}</a>}
                        {a.conflict_override ? (
                          <p className="mt-1 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300"><AlertTriangle className="h-3 w-3" />Double-booked on purpose{a.override_note ? `: ${a.override_note}` : ''}</p>
                        ) : null}
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        {edit ? (
                          <Select aria-label="Assignment status" className="h-8 w-auto text-xs" value={a.status} onChange={(e) => send('status', a.id, { status: e.target.value })} options={config.crew_assignment_statuses} disabled={busy || a.status === 'reassigned'} />
                        ) : (
                          <Badge tone={crewStatusTone[a.status]}>{config.crew_assignment_statuses[a.status]}</Badge>
                        )}
                        <WaIcon href={a.wa_link} />
                        {edit && !INACTIVE.includes(a.status) && (
                          <Button size="sm" variant="ghost" icon={<Repeat className="h-4 w-4" />} onClick={() => setReassign(a)}>Reassign</Button>
                        )}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            </section>

            {s.audit?.length > 0 && (
              <section>
                <h3 className="mb-2 text-sm font-semibold">History</h3>
                <ul className="space-y-1.5 text-xs text-muted">
                  {s.audit.map((l: any) => (
                    <li key={l.id}>
                      <span className="text-ink">{l.summary}</span> · {l.user_name || 'System'} · {fmtDateTime(l.created_at)}
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>
        ) : null}
      </Drawer>
      <ReassignModal a={reassign} busy={busy} onClose={() => setReassign(null)} onSave={(cid, rate) => send('reassign', reassign.id, { crew_member_id: cid, rate })} />
      <OverrideModal open={!!pending} conflicts={pending?.conflicts || []} busy={busy} onCancel={() => setPending(null)} onConfirm={(note) => pending && send(pending.kind, pending.aid, { ...pending.body, override: true, override_note: note || null })} />
    </>
  );
}

function ReassignModal({ a, onClose, onSave, busy }: { a: any; onClose: () => void; onSave: (id: number, rate?: string) => void; busy: boolean }) {
  const { config, can } = useAuth();
  const money = can('crew.payments');
  const crew = useCrew(!!a);
  const [cid, setCid] = useState('');
  const [rate, setRate] = useState('');
  const opts = (crew.data?.items || []).filter((m) => a && m.id !== a.crew_member_id && m.availability !== 'inactive');
  const sameRole = opts.filter((m) => a && m.category === a.role);
  const others = opts.filter((m) => a && m.category !== a.role);
  return (
    <Modal
      open={!!a}
      onClose={onClose}
      size="sm"
      title={`Reassign ${a?.crew_name || ''}`}
      subtitle={`${a?.crew_name} is marked Reassigned. The new person gets the same role and service.`}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} disabled={!cid} onClick={() => onSave(Number(cid), money ? rate : undefined)}>Reassign</Button>
        </>
      }
    >
      <Field label="Who takes over?">
        <Select
          value={cid}
          onChange={(e) => {
            setCid(e.target.value);
            const m = opts.find((x) => String(x.id) === e.target.value);
            setRate(m?.daily_rate != null ? String(m.daily_rate) : a?.rate != null ? String(a.rate) : '');
          }}
          placeholder="Choose crew member…"
          options={[...sameRole, ...others].map((m) => [String(m.id), `${m.name} · ${config.crew_categories[m.category]}${m.city ? ' · ' + m.city : ''}`] as [string, string])}
        />
      </Field>
      {money && cid && (
        <Field label="Rate for this event (₹)" className="mt-3" hint={a?.rate != null ? `${a.crew_name} had ${inr(a.rate)}.` : undefined}>
          <Input value={rate} onChange={(e) => setRate(e.target.value)} inputMode="decimal" />
        </Field>
      )}
    </Modal>
  );
}
