import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { ChevronLeft, ChevronRight, Clock, MapPin, Phone } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam } from '@/lib/data';
import { useUrlState } from '@/lib/hooks';
import { dayjs, fmtDateLong, fmtTime, phoneDisplay, telLink } from '@/lib/format';
import { bookingTone, eventColor, stageTone } from '@/lib/tones';
import { Avatar, Badge, Button, Card, Drawer, ErrorState, PageHeader, Segmented, Select, Spinner, Toggle } from '@/components/ui';

type View = 'month' | 'week' | 'day';
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function CalendarPage() {
  const { config, can } = useAuth();
  const team = useTeam();
  const [f, setF] = useUrlState({ view: 'month', date: dayjs().format('YYYY-MM-DD'), member_id: '', event_type: '', leads: '1' });
  const view = f.view as View;
  const cur = dayjs(f.date);
  const [sel, setSel] = useState<any>(null);

  // Monday-based ranges.
  const startOfWeek = (d: dayjs.Dayjs) => d.subtract((d.day() + 6) % 7, 'day');
  const range = useMemo(() => {
    if (view === 'day') return [cur, cur];
    if (view === 'week') {
      const s = startOfWeek(cur);
      return [s, s.add(6, 'day')];
    }
    const s = startOfWeek(cur.startOf('month'));
    const e = startOfWeek(cur.endOf('month')).add(6, 'day');
    return [s, e];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, f.date]);
  const from = range[0].format('YYYY-MM-DD');
  const to = range[1].format('YYYY-MM-DD');
  const showLeads = f.leads === '1' && can('leads.view');
  const params = { from, to, member_id: f.member_id, event_type: f.event_type, include_leads: showLeads ? 1 : '' };
  const q = useQuery({ queryKey: ['calendar', params], queryFn: () => api.get('calendar', params), placeholderData: keepPreviousData });

  const byDay = useMemo(() => {
    const m: Record<string, { events: any[]; leads: any[] }> = {};
    const slot = (d: string) => (m[d] ||= { events: [], leads: [] });
    (q.data?.events || []).forEach((e: any) => slot(e.event_date).events.push(e));
    (q.data?.tentative || []).forEach((l: any) => {
      if (f.event_type && l.event_type !== f.event_type) return;
      slot(l.event_date).leads.push(l);
    });
    return m;
  }, [q.data, f.event_type]);

  const step = (n: number) => setF({ date: cur.add(n, view === 'month' ? 'month' : view === 'week' ? 'week' : 'day').format('YYYY-MM-DD') });
  const title = view === 'month' ? cur.format('MMMM YYYY') : view === 'week' ? `${range[0].format('DD MMM')} – ${range[1].format('DD MMM YYYY')}` : cur.format('dddd, DD MMMM YYYY');
  const todayStr = dayjs().format('YYYY-MM-DD');
  const usedTypes = Array.from(new Set((q.data?.events || []).map((e: any) => e.type).filter(Boolean))) as string[];

  const days: dayjs.Dayjs[] = [];
  for (let d = range[0]; !d.isAfter(range[1]); d = d.add(1, 'day')) days.push(d);

  return (
    <div>
      <PageHeader
        title="Calendar"
        subtitle="Every shoot across your team. Dashed cards are leads that haven't booked yet."
        actions={
          <>
            {can('team.view') && <Select value={f.member_id} onChange={(e) => setF({ member_id: e.target.value })} options={teamOptions(team.data)} placeholder="Whole team" className="w-auto" />}
            <Select value={f.event_type} onChange={(e) => setF({ event_type: e.target.value })} options={config.event_types} placeholder="All events" className="w-auto" />
            {can('leads.view') && <Toggle checked={f.leads === '1'} onChange={(v) => setF({ leads: v ? '1' : '0' })} label="Tentative leads" />}
          </>
        }
      />
      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-line p-3">
          <div className="flex items-center gap-1">
            <Button size="sm" onClick={() => step(-1)} aria-label="Previous"><ChevronLeft className="h-4 w-4" /></Button>
            <Button size="sm" onClick={() => setF({ date: todayStr })}>Today</Button>
            <Button size="sm" onClick={() => step(1)} aria-label="Next"><ChevronRight className="h-4 w-4" /></Button>
          </div>
          <h2 className="ml-1 text-base font-semibold">{title}</h2>
          {q.isFetching && <Spinner className="h-4 w-4" />}
          <div className="ml-auto">
            <Segmented value={view} onChange={(v) => setF({ view: v })} options={[['month', 'Month'], ['week', 'Week'], ['day', 'Day']]} />
          </div>
        </div>

        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : view === 'month' ? (
          <div className="overflow-x-auto">
            <div className="min-w-[700px]">
              <div className="grid grid-cols-7 border-b border-line bg-subtle/50 text-center text-2xs font-medium uppercase tracking-wide text-muted">
                {DAYS.map((d) => <div key={d} className="py-2">{d}</div>)}
              </div>
              <div className="grid grid-cols-7">
                {days.map((d) => {
                  const ds = d.format('YYYY-MM-DD');
                  const cell = byDay[ds];
                  const items = [...(cell?.events || []), ...(cell?.leads || []).map((l) => ({ ...l, _lead: true }))];
                  const other = d.month() !== cur.month();
                  return (
                    <div key={ds} className={clsx('min-h-[112px] border-b border-r border-line p-1.5 [&:nth-child(7n)]:border-r-0', other && 'bg-subtle/40')}>
                      <button onClick={() => setF({ view: 'day', date: ds })} className={clsx('mb-1 flex h-6 w-6 items-center justify-center rounded-full text-xs', ds === todayStr ? 'bg-accent font-semibold text-white dark:text-slate-950' : other ? 'text-faint' : 'text-ink hover:bg-subtle')}>
                        {d.date()}
                      </button>
                      <div className="space-y-1">
                        {items.slice(0, 3).map((e: any) => <Chip key={(e._lead ? 'l' : 'e') + e.id} e={e} onClick={() => setSel(e)} />)}
                        {items.length > 3 && <button onClick={() => setF({ view: 'day', date: ds })} className="px-1 text-2xs font-medium text-muted hover:text-ink">+{items.length - 3} more</button>}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        ) : view === 'week' ? (
          <div className="overflow-x-auto">
            <div className="grid min-w-[840px] grid-cols-7 divide-x divide-line">
              {days.map((d) => {
                const ds = d.format('YYYY-MM-DD');
                const cell = byDay[ds];
                return (
                  <div key={ds} className="min-h-[420px]">
                    <button onClick={() => setF({ view: 'day', date: ds })} className={clsx('w-full border-b border-line py-2 text-center', ds === todayStr && 'bg-accent-soft')}>
                      <span className="block text-2xs font-medium uppercase text-muted">{d.format('ddd')}</span>
                      <span className={clsx('text-lg font-semibold', ds === todayStr && 'text-accent-ink')}>{d.date()}</span>
                    </button>
                    <div className="space-y-1.5 p-1.5">
                      {(cell?.events || []).map((e) => <Card2 key={e.id} e={e} onClick={() => setSel(e)} />)}
                      {(cell?.leads || []).map((l) => <Card2 key={'l' + l.id} e={{ ...l, _lead: true }} onClick={() => setSel({ ...l, _lead: true })} />)}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        ) : (
          <div className="p-4">
            {!byDay[from]?.events.length && !byDay[from]?.leads.length ? (
              <p className="py-12 text-center text-sm text-muted">No shoots on this day.</p>
            ) : (
              <ul className="space-y-3">
                {(byDay[from]?.events || []).map((e) => (
                  <li key={e.id} className="flex gap-4">
                    <div className="w-20 shrink-0 pt-3 text-right text-xs text-muted">{e.start_time ? fmtTime(e.start_time) : 'All day'}{e.end_time && <><br />{fmtTime(e.end_time)}</>}</div>
                    <div className="flex-1"><Card2 e={e} onClick={() => setSel(e)} large /></div>
                  </li>
                ))}
                {(byDay[from]?.leads || []).map((l) => (
                  <li key={'l' + l.id} className="flex gap-4">
                    <div className="w-20 shrink-0 pt-3 text-right text-xs text-muted">Tentative</div>
                    <div className="flex-1"><Card2 e={{ ...l, _lead: true }} onClick={() => setSel({ ...l, _lead: true })} large /></div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
        {usedTypes.length > 0 && (
          <div className="flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line px-4 py-3 text-xs text-muted">
            {usedTypes.map((t) => (
              <span key={t} className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: eventColor[t] || eventColor.other }} />{config.event_types[t]}</span>
            ))}
            {showLeads && <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm border border-dashed border-muted" />Tentative lead</span>}
          </div>
        )}
      </Card>
      <EventDrawer e={sel} onClose={() => setSel(null)} />
    </div>
  );
}

const color = (e: any) => eventColor[e.type || e.event_type] || eventColor.other;

function Chip({ e, onClick }: { e: any; onClick: () => void }) {
  return (
    <button onClick={onClick} className={clsx('flex w-full items-center gap-1 truncate rounded px-1.5 py-0.5 text-left text-2xs font-medium', e._lead ? 'border border-dashed border-line text-muted hover:bg-subtle' : 'text-white hover:opacity-90')} style={e._lead ? { borderColor: color(e) } : { background: color(e) }} title={e._lead ? e.name : `${e.client_name} – ${e.name}`}>
      {!e._lead && e.start_time && <span className="opacity-80">{dayjs('2000-01-01 ' + e.start_time).format(e.start_time.slice(3, 5) === '00' ? 'hA' : 'h:mmA')}</span>}
      <span className="truncate">{e._lead ? e.name : e.client_name}</span>
    </button>
  );
}

function Card2({ e, onClick, large }: { e: any; onClick: () => void; large?: boolean }) {
  return (
    <button onClick={onClick} className={clsx('w-full rounded-lg border p-2 text-left text-xs transition-shadow hover:shadow-card', e._lead ? 'border-dashed' : 'border-line bg-surface')} style={{ borderLeft: `3px ${e._lead ? 'dashed' : 'solid'} ${color(e)}` }}>
      <p className={clsx('font-medium', large && 'text-sm')}>{e._lead ? e.name : e.client_name}</p>
      <p className="truncate text-muted">{e._lead ? `${e.event_type ? '' : ''}Lead · tentative` : e.name}</p>
      {!e._lead && e.start_time && !large && <p className="text-muted">{fmtTime(e.start_time)}</p>}
      {(e.venue || e.city) && <p className="truncate text-faint">{[e.venue, e.city].filter(Boolean).join(', ')}</p>}
      {!e._lead && (e.crew || e.team)?.length > 0 && (
        <div className="mt-1.5 flex -space-x-1">{(e.crew || e.team).slice(0, 5).map((t: any) => <Avatar key={t.member_id} name={t.name} color={t.color} size="xs" />)}</div>
      )}
    </button>
  );
}

function EventDrawer({ e, onClose }: { e: any; onClose: () => void }) {
  const { config } = useAuth();
  return (
    <Drawer open={!!e} onClose={onClose} title={e ? (e._lead ? e.name : e.name + ' · ' + e.client_name) : ''} width="max-w-md">
      {e && (
        <div className="space-y-4 text-sm">
          <div className="flex flex-wrap gap-2">
            <Badge tone="gray"><span className="mr-1 inline-block h-2 w-2 rounded-full" style={{ background: color(e) }} />{config.event_types[e.type || e.event_type] || 'Event'}</Badge>
            {e._lead ? <Badge tone={stageTone[e.stage]}>{config.stages[e.stage]}</Badge> : <Badge tone={bookingTone[e.booking_status]}>{config.booking_statuses[e.booking_status]}</Badge>}
          </div>
          <p className="font-medium">{fmtDateLong(e.event_date)}</p>
          {e.start_time && <p className="flex items-center gap-2 text-muted"><Clock className="h-4 w-4" />{fmtTime(e.start_time)}{e.end_time ? ' – ' + fmtTime(e.end_time) : ''}</p>}
          {(e.venue || e.city) && <p className="flex items-center gap-2 text-muted"><MapPin className="h-4 w-4" />{[e.venue, e.city].filter(Boolean).join(', ')}</p>}
          {!e._lead && e.client_phone && <p className="flex items-center gap-2"><Phone className="h-4 w-4 text-muted" /><a href={telLink(e.client_phone)} className="hover:text-accent-ink">{phoneDisplay(e.client_phone)}</a></p>}
          {!e._lead && (
            <div>
              <p className="label">Team</p>
              {e.team?.length ? (
                <ul className="space-y-1.5">{e.team.map((t: any) => <li key={t.member_id} className="flex items-center gap-2"><Avatar name={t.name} color={t.color} size="sm" />{t.name}<span className="text-xs text-muted">{config.event_roles[t.role]}</span></li>)}</ul>
              ) : !e.services?.some((s: any) => s.on_site) ? (
                <p className="text-amber-700 dark:text-amber-300">No team assigned</p>
              ) : null}
              {e.services?.length > 0 && (
                <ul className="mt-2 space-y-2">
                  {e.services.map((s: any, i: number) => (
                    <li key={i}>
                      <p className="text-xs font-medium">{s.name}{!s.on_site && <span className="font-normal text-muted"> · post-production</span>}</p>
                      <div className="mt-1 flex flex-wrap gap-1.5">{s.team.map((t: any) => <span key={t.member_id} className="inline-flex items-center gap-1 rounded-full bg-subtle py-0.5 pl-0.5 pr-2 text-xs"><Avatar name={t.name} color={t.color} size="xs" />{t.name}</span>)}</div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
          {e.notes && <p className="text-muted">{e.notes}</p>}
          <Link to={e._lead ? `/leads/${e.id}` : `/bookings/${e.booking_id}`} onClick={onClose} className="inline-flex h-9 items-center rounded-lg bg-accent px-3.5 text-sm font-medium text-white dark:text-slate-950">
            {e._lead ? 'Open lead' : `Open booking ${e.booking_number}`}
          </Link>
        </div>
      )}
    </Drawer>
  );
}
