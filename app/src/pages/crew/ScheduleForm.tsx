import { useEffect, useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { api, ApiError, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { fmtDateShort, inr } from '@/lib/format';
import { Button, Drawer, Field, IconButton, Input, Select, Textarea, useUi } from '@/components/ui';
import { ConflictList, CrewModal, INACTIVE, OverrideModal, useCrew } from './shared';

type Row = { key: string; id?: number; crew_member_id: string; role: string; service: string; rate: string; status: string; notes: string };
type Form = Record<string, string>;

const blankForm: Form = { booking_id: '', booking_event_id: '', client_name: '', event_name: '', event_type: '', event_date: '', start_time: '', reporting_time: '', end_time: '', venue: '', city: '', transport: '', accommodation: '', instructions: '' };
let seq = 0;
const newRow = (p: Partial<Row> = {}): Row => ({ key: 'r' + ++seq, crew_member_id: '', role: '', service: '', rate: '', status: 'scheduled', notes: '', ...p });
const t5 = (t?: string | null) => (t ? String(t).slice(0, 5) : '');

/**
 * Create or edit a crew schedule (one event day). `initial` may be an existing
 * schedule (edit), or a partial prefill such as { booking_id, event_date } or a
 * schedule to copy to another day (`copy: true`).
 */
export default function ScheduleForm({ initial, onClose, onSaved }: { initial: any | null; onClose: () => void; onSaved?: (s: any) => void }) {
  const { config, can } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const money = can('crew.payments');
  const crew = useCrew(!!initial);
  const isEdit = !!initial?.id && !initial?.copy;
  const [v, setV] = useState<Form>(blankForm);
  const [rows, setRows] = useState<Row[]>([newRow()]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [override, setOverride] = useState<any[] | null>(null);
  const [addCrew, setAddCrew] = useState<{ row: string } | null>(null);

  useEffect(() => {
    if (!initial) return;
    const f: Form = { ...blankForm };
    Object.keys(blankForm).forEach((k) => {
      const x = initial[k];
      if (x !== undefined && x !== null) f[k] = k.endsWith('_time') ? t5(x) : String(x);
    });
    if (initial.copy) {
      f.event_date = '';
      f.booking_event_id = '';
    }
    setV(f);
    setErrors({});
    const list = (initial.assignments || []).filter((a: any) => !initial.copy || !INACTIVE.includes(a.status));
    setRows(
      list.length
        ? list.map((a: any) =>
            newRow({
              id: initial.copy ? undefined : a.id,
              crew_member_id: String(a.crew_member_id),
              role: a.role,
              service: a.service || '',
              rate: a.rate != null ? String(a.rate) : '',
              status: initial.copy ? 'scheduled' : a.status,
              notes: a.notes || '',
            }),
          )
        : [newRow()],
    );
  }, [initial]);

  const set = (k: string, val: string) => {
    setV((s) => ({ ...s, [k]: val }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const setRow = (key: string, patch: Partial<Row>) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));

  // Bookings to link, then the selected booking's events to prefill from.
  const bookings = useQuery({ queryKey: ['bookings', 'crew-pick'], queryFn: () => api.get('bookings', { per_page: 200 }), enabled: !!initial && can('bookings.view') });
  const booking = useQuery({ queryKey: ['booking', v.booking_id], queryFn: () => api.get(`bookings/${v.booking_id}`), enabled: !!v.booking_id && can('bookings.view') });
  const events: any[] = booking.data?.events || [];

  const pickBooking = (id: string) => {
    setV((s) => ({ ...s, booking_id: id, booking_event_id: '' }));
  };
  useEffect(() => {
    // When a booking loads, fill the client name if it is still empty.
    if (booking.data && !v.client_name) setV((s) => ({ ...s, client_name: booking.data.client?.name || '' }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [booking.data]);

  const pickEvent = (id: string) => {
    const e = events.find((x) => String(x.id) === id);
    if (!e) return set('booking_event_id', '');
    setV((s) => ({
      ...s,
      booking_event_id: id,
      event_name: e.name || s.event_name,
      event_type: e.event_type || s.event_type,
      event_date: e.event_date || s.event_date,
      start_time: t5(e.start_time) || s.start_time,
      end_time: t5(e.end_time) || s.end_time,
      venue: e.venue || s.venue,
      city: e.city || s.city,
      client_name: s.client_name || booking.data?.client?.name || '',
    }));
  };

  const members = crew.data?.items || [];
  const byId = useMemo(() => Object.fromEntries(members.map((m) => [String(m.id), m])), [members]);
  const crewOptions = useMemo(() => members.filter((m) => m.availability !== 'inactive' || rows.some((r) => r.crew_member_id === String(m.id))).map((m) => [String(m.id), `${m.name} · ${config.crew_categories[m.category] || m.category}${m.city ? ' · ' + m.city : ''}`] as [string, string]), [members, rows, config]);

  const pickCrew = (key: string, id: string) => {
    const m = byId[id];
    setRows((rs) =>
      rs.map((r) =>
        r.key === key
          ? { ...r, crew_member_id: id, role: r.role || m?.category || '', rate: r.rate || (m?.daily_rate != null ? String(m.daily_rate) : '') }
          : r,
      ),
    );
  };

  // Live conflict check while the form is filled.
  const activeIds = rows.filter((r) => r.crew_member_id && !INACTIVE.includes(r.status)).map((r) => Number(r.crew_member_id));
  const checkKey = useDebounce(JSON.stringify({ d: v.event_date, s: v.start_time, r: v.reporting_time, e: v.end_time, ids: activeIds }), 400);
  const conflicts = useQuery({
    queryKey: ['crew-conflicts', checkKey, initial?.id],
    queryFn: () =>
      api.post<Record<string, any[]>>('crew-schedules/conflicts', {
        event_date: v.event_date,
        start_time: v.start_time,
        reporting_time: v.reporting_time,
        end_time: v.end_time,
        crew_member_ids: activeIds,
        exclude_schedule_id: isEdit ? initial.id : 0,
      }),
    enabled: !!initial && !!v.event_date && activeIds.length > 0,
  });
  const clash = (id: string) => (conflicts.data && !Array.isArray(conflicts.data) ? conflicts.data[id] : null) || null;
  const dupIds = activeIds.filter((id, i) => activeIds.indexOf(id) !== i);

  const submit = async (overrideNote?: string) => {
    const e: Record<string, string> = {};
    if (!v.event_name.trim()) e.event_name = 'Event name is required.';
    if (!v.event_date) e.event_date = 'Event date is required.';
    const filled = rows.filter((r) => r.crew_member_id || r.role || r.service || r.rate);
    filled.forEach((r, i) => {
      if (!r.crew_member_id) e['row.' + r.key] = 'Choose a crew member.';
      else if (!r.role) e['row.' + r.key] = 'Choose the role.';
      else if (r.rate && (isNaN(Number(r.rate)) || Number(r.rate) < 0)) e['row.' + r.key] = 'Rate must be a positive number.';
      void i;
    });
    if (dupIds.length) e.rows = 'The same crew member is added twice.';
    if (Object.keys(e).length) {
      setErrors(e);
      toast(Object.values(e)[0], 'error');
      return;
    }
    const body: any = {};
    Object.entries(v).forEach(([k, x]) => (body[k] = x === '' ? null : x));
    body.assignments = filled.map((r) => {
      const a: any = { crew_member_id: Number(r.crew_member_id), role: r.role, service: r.service || null, status: r.status, notes: r.notes || null };
      if (r.id) a.id = r.id;
      if (money) a.rate = r.rate === '' ? null : Number(r.rate);
      return a;
    });
    if (overrideNote !== undefined) {
      body.override_conflicts = true;
      body.override_note = overrideNote || null;
    }
    setBusy(true);
    try {
      const s = isEdit ? await api.put(`crew-schedules/${initial.id}`, body) : await api.post('crew-schedules', body);
      qc.invalidateQueries({ queryKey: ['crew-schedules'] });
      qc.invalidateQueries({ queryKey: ['crew-schedule'] });
      qc.invalidateQueries({ queryKey: ['crew'] });
      qc.invalidateQueries({ queryKey: ['crew-payments'] });
      toast(isEdit ? 'Schedule updated' : `Crew scheduled for ${s.event_name}`);
      setOverride(null);
      onSaved?.(s);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.code === 'crew_conflict') {
        setOverride(err.data.conflicts || []);
      } else {
        if (err instanceof ApiError) {
          const se: Record<string, string> = { ...err.errors };
          Object.keys(err.errors).forEach((k) => {
            const m = /^assignments\.(\d+)$/.exec(k);
            if (m && filled[Number(m[1])]) se['row.' + filled[Number(m[1])].key] = err.errors[k];
          });
          setErrors(se);
        }
        toast(errMsg(err), 'error');
      }
    } finally {
      setBusy(false);
    }
  };

  const total = rows.reduce((s, r) => s + (r.rate && !INACTIVE.includes(r.status) ? Number(r.rate) || 0 : 0), 0);

  return (
    <>
      <Drawer
        open={!!initial}
        onClose={onClose}
        width="max-w-3xl"
        title={isEdit ? `Edit schedule · ${initial?.event_name}` : initial?.copy && initial?.event_name ? 'Schedule another day' : 'Schedule crew'}
        footer={
          <>
            <Button onClick={onClose}>Cancel</Button>
            <Button variant="primary" loading={busy && !override} onClick={() => submit()}>{isEdit ? 'Save changes' : 'Save schedule'}</Button>
          </>
        }
      >
        <div className="space-y-6 p-5">
          <section className="space-y-4">
            <h3 className="text-sm font-semibold">Event</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              {can('bookings.view') && (
                <Field label="Booking" hint="Optional. Links the crew to a client booking." error={errors.booking_id}>
                  <Select value={v.booking_id} onChange={(e) => pickBooking(e.target.value)} options={(bookings.data?.items || []).map((b: any) => [String(b.id), `${b.number} · ${b.title}`] as [string, string])} placeholder={bookings.isLoading ? 'Loading…' : 'No booking (standalone event)'} />
                </Field>
              )}
              {v.booking_id && (
                <Field label="Booking event" hint={events.length ? 'Fills the date, time and venue.' : booking.isLoading ? 'Loading events…' : 'This booking has no events yet.'} error={errors.booking_event_id}>
                  <Select value={v.booking_event_id} onChange={(e) => pickEvent(e.target.value)} options={events.map((e) => [String(e.id), `${e.name} · ${fmtDateShort(e.event_date)}`] as [string, string])} placeholder="Choose an event…" />
                </Field>
              )}
              <Field label="Client name" error={errors.client_name}><Input value={v.client_name} onChange={(e) => set('client_name', e.target.value)} /></Field>
              <Field label="Event name" required error={errors.event_name}><Input value={v.event_name} onChange={(e) => set('event_name', e.target.value)} placeholder="Sangeet" /></Field>
              <Field label="Event type" error={errors.event_type}><Select value={v.event_type} onChange={(e) => set('event_type', e.target.value)} options={config.event_types} placeholder="Choose…" /></Field>
              <Field label="Event date" required error={errors.event_date}><Input type="date" value={v.event_date} onChange={(e) => set('event_date', e.target.value)} /></Field>
              <div className="grid grid-cols-3 gap-2 sm:col-span-2">
                <Field label="Reporting time" error={errors.reporting_time}><Input type="time" value={v.reporting_time} onChange={(e) => set('reporting_time', e.target.value)} /></Field>
                <Field label="Start time" error={errors.start_time}><Input type="time" value={v.start_time} onChange={(e) => set('start_time', e.target.value)} /></Field>
                <Field label="End time" error={errors.end_time}><Input type="time" value={v.end_time} onChange={(e) => set('end_time', e.target.value)} /></Field>
              </div>
              <Field label="Venue" error={errors.venue}><Input value={v.venue} onChange={(e) => set('venue', e.target.value)} /></Field>
              <Field label="City" error={errors.city}><Input value={v.city} onChange={(e) => set('city', e.target.value)} /></Field>
            </div>
          </section>

          <section className="space-y-3">
            <div className="flex items-center justify-between gap-2">
              <h3 className="text-sm font-semibold">Crew</h3>
              {money && total > 0 && <span className="text-xs text-muted">Crew cost {inr(total)}</span>}
            </div>
            {errors.rows && <p className="text-xs text-red-600">{errors.rows}</p>}
            <div className="space-y-3">
              {rows.map((r) => {
                const c = r.crew_member_id && !INACTIVE.includes(r.status) ? clash(r.crew_member_id) : null;
                const m = byId[r.crew_member_id];
                return (
                  <div key={r.key} className="rounded-xl border border-line p-3" data-testid="crew-row">
                    <div className="grid gap-2 sm:grid-cols-12">
                      <div className="sm:col-span-5">
                        <Select aria-label="Crew member" value={r.crew_member_id} onChange={(e) => (e.target.value === '__new' ? setAddCrew({ row: r.key }) : pickCrew(r.key, e.target.value))} options={[...crewOptions, ...(can('crew.edit') ? ([['__new', '+ Add new crew member…']] as [string, string][]) : [])]} placeholder={crew.isLoading ? 'Loading crew…' : 'Choose crew member…'} invalid={!!errors['row.' + r.key]} />
                      </div>
                      <div className="sm:col-span-4">
                        <Select aria-label="Role" value={r.role} onChange={(e) => setRow(r.key, { role: e.target.value })} options={config.crew_categories} placeholder="Role…" />
                      </div>
                      <div className="sm:col-span-3">
                        <Select aria-label="Status" value={r.status} onChange={(e) => setRow(r.key, { status: e.target.value })} options={config.crew_assignment_statuses} />
                      </div>
                      <div className={money ? 'sm:col-span-6' : 'sm:col-span-11'}>
                        <Input aria-label="Service" value={r.service} onChange={(e) => setRow(r.key, { service: e.target.value })} placeholder="Assigned service, e.g. Bride side candids" />
                      </div>
                      {money && (
                        <div className="sm:col-span-5">
                          <Input aria-label="Rate" value={r.rate} onChange={(e) => setRow(r.key, { rate: e.target.value })} inputMode="decimal" placeholder={m?.daily_rate ? `Daily rate ${inr(m.daily_rate)}` : 'Payment for this event (₹)'} />
                        </div>
                      )}
                      <div className="flex justify-end sm:col-span-1">
                        <IconButton label="Remove crew member" onClick={() => setRows((rs) => (rs.length > 1 ? rs.filter((x) => x.key !== r.key) : [newRow()]))}>
                          <Trash2 className="h-4 w-4" />
                        </IconButton>
                      </div>
                    </div>
                    {m && m.availability !== 'available' && <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">{m.name} is marked “{config.crew_availability[m.availability]}”.</p>}
                    {errors['row.' + r.key] && <p className="mt-2 text-xs text-red-600">{errors['row.' + r.key]}</p>}
                    {c && (
                      <div className="mt-2" data-testid="conflict-warning">
                        <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                          <AlertTriangle className="h-3.5 w-3.5" />
                          Already booked at this time
                        </p>
                        <ConflictList items={[{ conflicts: c }]} />
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
            <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setRows((rs) => [...rs, newRow()])}>Add crew member</Button>
          </section>

          <section className="space-y-4">
            <h3 className="text-sm font-semibold">Logistics</h3>
            <Field label="Transportation details"><Textarea rows={2} value={v.transport} onChange={(e) => set('transport', e.target.value)} placeholder="Studio car leaves office at 6 AM" /></Field>
            <Field label="Accommodation details"><Textarea rows={2} value={v.accommodation} onChange={(e) => set('accommodation', e.target.value)} placeholder="Hotel name, rooms, check-in" /></Field>
            <Field label="Special instructions" hint="Included in the WhatsApp message to crew."><Textarea rows={3} value={v.instructions} onChange={(e) => set('instructions', e.target.value)} placeholder="Dress code, gear, shot list…" /></Field>
          </section>
        </div>
      </Drawer>
      <OverrideModal open={!!override} conflicts={override || []} busy={busy} onCancel={() => setOverride(null)} onConfirm={(note) => submit(note)} />
      <CrewModal
        member={addCrew ? {} : null}
        onClose={() => setAddCrew(null)}
        onSaved={(c) => {
          const key = addCrew?.row;
          crew.refetch().then(() => key && pickCrew(key, String(c.id)));
          if (key) setRow(key, { crew_member_id: String(c.id), role: c.category, rate: c.daily_rate != null ? String(c.daily_rate) : '' });
        }}
      />
    </>
  );
}
