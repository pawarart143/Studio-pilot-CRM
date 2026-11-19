import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { CalendarDays, ChevronLeft, ChevronRight, Clock, MapPin, MoreHorizontal, Pencil, Phone, Plus, Trash2, UsersRound } from 'lucide-react';
import { api, ApiError, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { dayjs, fmtDateShort, inr, phoneDisplay, telLink } from '@/lib/format';
import { Avatar, Badge, Button, Card, EmptyState, ErrorState, IconButton, Menu, MenuItem, PageHeader, SearchInput, Segmented, Select, Spinner, TableSkeleton, Tabs, useUi } from '@/components/ui';
import { crewAvailTone, crewWa, CrewModal, type CrewMember, INACTIVE, timeRange, useCrew, WaIcon } from './shared';
import ScheduleForm from './ScheduleForm';
import ScheduleDetail from './ScheduleDetail';
import CrewPayments from './CrewPayments';

type Tab = 'schedule' | 'directory' | 'payments';

export default function CrewPage() {
  const { can } = useAuth();
  const [sp, setSp] = useSearchParams();
  const u = { tab: sp.get('tab') || 'schedule' };
  const tabs: [Tab, string][] = [['schedule', 'Schedule'], ['directory', 'Crew directory']];
  if (can('crew.payments')) tabs.push(['payments', 'Crew payments']);
  const tab = (tabs.some((t) => t[0] === u.tab) ? u.tab : 'schedule') as Tab;
  const [form, setForm] = useState<any>(null);
  const [crewEdit, setCrewEdit] = useState<Partial<CrewMember> | null>(null);

  return (
    <div>
      <PageHeader
        title="Crew Scheduling"
        subtitle="Freelance and outside crew for your events. Separate from your Team."
        actions={
          can('crew.edit') && (
            <>
              <Button icon={<Plus className="h-4 w-4" />} onClick={() => setCrewEdit({})}>Add crew member</Button>
              <Button variant="primary" icon={<CalendarDays className="h-4 w-4" />} onClick={() => setForm({})}>Schedule crew</Button>
            </>
          )
        }
      />
      <Tabs value={tab} onChange={(t) => setSp(t === 'schedule' ? {} : { tab: t }, { replace: true })} tabs={tabs} className="mb-4" />
      {tab === 'schedule' && <ScheduleTab onNew={(p) => setForm(p)} onEdit={(s) => setForm(s)} />}
      {tab === 'directory' && <DirectoryTab onEdit={setCrewEdit} />}
      {tab === 'payments' && <CrewPayments />}
      <ScheduleForm initial={form} onClose={() => setForm(null)} />
      <CrewModal member={crewEdit} onClose={() => setCrewEdit(null)} />
    </div>
  );
}

/* ---------------- Schedule / calendar ---------------- */
type View = 'month' | 'week' | 'day' | 'list';
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const startOfWeek = (d: dayjs.Dayjs) => d.subtract((d.day() + 6) % 7, 'day');

function ScheduleTab({ onNew, onEdit }: { onNew: (p: any) => void; onEdit: (s: any) => void }) {
  const { config, can } = useAuth();
  const crew = useCrew();
  const [f, setF] = useUrlState({ view: 'month', date: dayjs().format('YYYY-MM-DD'), q: '', crew_member_id: '', category: '', city: '', status: '', from: '', to: '' });
  const view = f.view as View;
  const cur = dayjs(f.date);
  const [sel, setSel] = useState<number | null>(null);
  const dq = useDebounce(f.q, 300);

  const range = useMemo(() => {
    if (view === 'day') return [cur, cur];
    if (view === 'week') {
      const s = startOfWeek(cur);
      return [s, s.add(6, 'day')];
    }
    if (view === 'list') return [f.from ? dayjs(f.from) : cur.startOf('month'), f.to ? dayjs(f.to) : cur.startOf('month').add(2, 'month').subtract(1, 'day')];
    return [startOfWeek(cur.startOf('month')), startOfWeek(cur.endOf('month')).add(6, 'day')];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, f.date, f.from, f.to]);
  const from = range[0].format('YYYY-MM-DD');
  const to = range[1].format('YYYY-MM-DD');
  const params = { from, to, q: dq, crew_member_id: f.crew_member_id, category: f.category, city: f.city, status: f.status };
  const q = useQuery({ queryKey: ['crew-schedules', params], queryFn: () => api.get<{ items: any[]; cities: string[] }>('crew-schedules', params), placeholderData: keepPreviousData });
  const items = q.data?.items || [];
  const byDay = useMemo(() => {
    const m: Record<string, any[]> = {};
    items.forEach((s) => (m[s.event_date] ||= []).push(s));
    return m;
  }, [items]);

  const step = (n: number) => setF({ date: cur.add(n, view === 'week' ? 'week' : view === 'day' ? 'day' : 'month').format('YYYY-MM-DD'), from: '', to: '' });
  const title = view === 'month' ? cur.format('MMMM YYYY') : view === 'week' ? `${range[0].format('DD MMM')} – ${range[1].format('DD MMM YYYY')}` : view === 'day' ? cur.format('dddd, DD MMMM YYYY') : `${range[0].format('DD MMM')} – ${range[1].format('DD MMM YYYY')}`;
  const todayStr = dayjs().format('YYYY-MM-DD');
  const days: dayjs.Dayjs[] = [];
  if (view !== 'list') for (let d = range[0]; !d.isAfter(range[1]); d = d.add(1, 'day')) days.push(d);
  const filtered = !!(f.q || f.crew_member_id || f.category || f.city || f.status);

  return (
    <>
      <Card className="mb-3 p-3">
        <div className="flex flex-wrap gap-2">
          <SearchInput value={f.q} onChange={(v) => setF({ q: v })} placeholder="Search event, client, venue…" className="w-full sm:w-60" />
          <Select aria-label="Crew member" className="w-auto" value={f.crew_member_id} onChange={(e) => setF({ crew_member_id: e.target.value })} options={(crew.data?.items || []).map((m) => [String(m.id), m.name] as [string, string])} placeholder="All crew" />
          <Select aria-label="Category" className="w-auto" value={f.category} onChange={(e) => setF({ category: e.target.value })} options={config.crew_categories} placeholder="All roles" />
          <Select aria-label="City" className="w-auto" value={f.city} onChange={(e) => setF({ city: e.target.value })} options={(q.data?.cities || []).map((c) => [c, c] as [string, string])} placeholder="All cities" />
          <Select aria-label="Status" className="w-auto" value={f.status} onChange={(e) => setF({ status: e.target.value })} options={config.crew_assignment_statuses} placeholder="Any status" />
          {filtered && <Button variant="ghost" size="sm" className="h-9" onClick={() => setF({ q: '', crew_member_id: '', category: '', city: '', status: '' })}>Clear</Button>}
        </div>
      </Card>
      <Card>
        <div className="flex flex-wrap items-center gap-2 border-b border-line p-3">
          <div className="flex items-center gap-1">
            <Button size="sm" onClick={() => step(-1)} aria-label="Previous"><ChevronLeft className="h-4 w-4" /></Button>
            <Button size="sm" onClick={() => setF({ date: todayStr, from: '', to: '' })}>Today</Button>
            <Button size="sm" onClick={() => step(1)} aria-label="Next"><ChevronRight className="h-4 w-4" /></Button>
          </div>
          <h2 className="ml-1 text-base font-semibold">{title}</h2>
          {q.isFetching && <Spinner className="h-4 w-4" />}
          {view === 'list' && (
            <div className="flex items-center gap-1 text-xs text-muted">
              <input type="date" aria-label="From" className="h-8 rounded-lg border border-line bg-surface px-2 text-xs" value={from} onChange={(e) => setF({ from: e.target.value })} />
              to
              <input type="date" aria-label="To" className="h-8 rounded-lg border border-line bg-surface px-2 text-xs" value={to} onChange={(e) => setF({ to: e.target.value })} />
            </div>
          )}
          <div className="ml-auto">
            <Segmented value={view} onChange={(v) => setF({ view: v })} options={[['month', 'Month'], ['week', 'Week'], ['day', 'Day'], ['list', 'List']]} />
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
                  const list = byDay[ds] || [];
                  const other = d.month() !== cur.month();
                  return (
                    <div key={ds} className={clsx('group min-h-[112px] border-b border-r border-line p-1.5 [&:nth-child(7n)]:border-r-0', other && 'bg-subtle/40')}>
                      <div className="mb-1 flex items-center justify-between">
                        <button onClick={() => setF({ view: 'day', date: ds })} className={clsx('flex h-6 w-6 items-center justify-center rounded-full text-xs', ds === todayStr ? 'bg-accent font-semibold text-white dark:text-slate-950' : other ? 'text-faint' : 'text-ink hover:bg-subtle')}>{d.date()}</button>
                        {can('crew.edit') && (
                          <button onClick={() => onNew({ event_date: ds })} className="hidden h-5 w-5 items-center justify-center rounded text-muted hover:bg-subtle group-hover:flex" aria-label={`Schedule crew on ${ds}`}><Plus className="h-3.5 w-3.5" /></button>
                        )}
                      </div>
                      <div className="space-y-1">
                        {list.slice(0, 3).map((s) => <Chip key={s.id} s={s} onClick={() => setSel(s.id)} />)}
                        {list.length > 3 && <button onClick={() => setF({ view: 'day', date: ds })} className="px-1 text-2xs font-medium text-muted hover:text-ink">+{list.length - 3} more</button>}
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
                return (
                  <div key={ds} className="min-h-[420px]">
                    <button onClick={() => setF({ view: 'day', date: ds })} className={clsx('w-full border-b border-line py-2 text-center', ds === todayStr && 'bg-accent-soft')}>
                      <span className="block text-2xs font-medium uppercase text-muted">{d.format('ddd')}</span>
                      <span className={clsx('text-lg font-semibold', ds === todayStr && 'text-accent-ink')}>{d.date()}</span>
                    </button>
                    <div className="space-y-1.5 p-1.5">{(byDay[ds] || []).map((s) => <SchedCard key={s.id} s={s} onClick={() => setSel(s.id)} />)}</div>
                  </div>
                );
              })}
            </div>
          </div>
        ) : view === 'day' ? (
          <div className="p-4">
            {!(byDay[from] || []).length ? (
              <EmptyState icon={<CalendarDays className="h-5 w-5" />} title="No crew scheduled on this day" action={can('crew.edit') && <Button variant="primary" onClick={() => onNew({ event_date: from })}>Schedule crew</Button>} />
            ) : (
              <ul className="space-y-3">
                {(byDay[from] || []).map((s) => (
                  <li key={s.id}><SchedCard s={s} onClick={() => setSel(s.id)} large /></li>
                ))}
              </ul>
            )}
          </div>
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : !items.length ? (
          <EmptyState icon={<CalendarDays className="h-5 w-5" />} title={filtered ? 'No schedules match these filters' : 'No crew scheduled in this period'} action={can('crew.edit') && !filtered && <Button variant="primary" onClick={() => onNew({})}>Schedule crew</Button>} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead className="border-b border-line bg-subtle/50 text-left text-2xs uppercase tracking-wide text-muted">
                <tr><th className="px-4 py-2">Date</th><th className="px-4 py-2">Event</th><th className="px-4 py-2">Time</th><th className="px-4 py-2">Venue</th><th className="px-4 py-2">Crew</th></tr>
              </thead>
              <tbody className="divide-y divide-line">
                {items.map((s) => (
                  <tr key={s.id} className="cursor-pointer hover:bg-subtle/50" onClick={() => setSel(s.id)}>
                    <td className="whitespace-nowrap px-4 py-2.5">{fmtDateShort(s.event_date)}<span className="block text-xs text-muted">{dayjs(s.event_date).format('ddd')}</span></td>
                    <td className="px-4 py-2.5"><p className="font-medium">{s.event_name}</p><p className="text-xs text-muted">{s.client_name || '—'}{s.booking_number ? ` · ${s.booking_number}` : ''}</p></td>
                    <td className="px-4 py-2.5 text-xs text-muted">{timeRange(s)}</td>
                    <td className="px-4 py-2.5 text-xs text-muted">{[s.venue, s.city].filter(Boolean).join(', ') || '—'}</td>
                    <td className="px-4 py-2.5"><CrewStack s={s} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <ScheduleDetail id={sel} onClose={() => setSel(null)} onEdit={(s) => (setSel(null), onEdit(s))} onCopy={(s) => (setSel(null), onNew({ ...s, copy: true }))} />
    </>
  );
}

const activeCrew = (s: any) => (s.assignments || []).filter((a: any) => !INACTIVE.includes(a.status));
const needsAttention = (s: any) => activeCrew(s).some((a: any) => a.status === 'confirmation_pending' || a.status === 'scheduled');

function Chip({ s, onClick }: { s: any; onClick: () => void }) {
  const n = activeCrew(s).length;
  return (
    <button onClick={onClick} className="flex w-full items-center gap-1 truncate rounded bg-accent-soft px-1.5 py-0.5 text-left text-2xs font-medium text-accent-ink hover:opacity-90" title={`${s.event_name}${s.client_name ? ' – ' + s.client_name : ''} · ${n} crew`}>
      <span className="truncate">{s.event_name}</span>
      <span className="ml-auto shrink-0 opacity-80">{n}</span>
    </button>
  );
}

function CrewStack({ s }: { s: any }) {
  const { config } = useAuth();
  const list = activeCrew(s);
  if (!list.length) return <span className="text-xs text-muted">No crew</span>;
  return (
    <div className="flex flex-wrap gap-1">
      {list.slice(0, 6).map((a: any) => (
        <span key={a.id} className="inline-flex items-center gap-1 rounded-full border border-line py-0.5 pl-0.5 pr-2 text-2xs" title={`${a.crew_name} · ${config.crew_categories[a.role]} · ${config.crew_assignment_statuses[a.status]}`}>
          <Avatar name={a.crew_name} src={a.crew_photo} size="xs" />
          {a.crew_name.split(' ')[0]}
          <span className={clsx('h-1.5 w-1.5 rounded-full', a.status === 'confirmed' || a.status === 'completed' ? 'bg-emerald-500' : 'bg-amber-500')} />
        </span>
      ))}
      {list.length > 6 && <span className="text-2xs text-muted">+{list.length - 6}</span>}
    </div>
  );
}

function SchedCard({ s, onClick, large }: { s: any; onClick: () => void; large?: boolean }) {
  return (
    <button onClick={onClick} className="w-full rounded-lg border border-line border-l-[3px] border-l-accent bg-surface p-2 text-left text-xs transition-shadow hover:shadow-card">
      <p className={clsx('font-medium', large && 'text-sm')}>{s.event_name}</p>
      {s.client_name && <p className="truncate text-muted">{s.client_name}</p>}
      <p className="flex items-center gap-1 text-muted"><Clock className="h-3 w-3" />{timeRange(s)}</p>
      {(s.venue || s.city) && <p className="flex items-center gap-1 truncate text-faint"><MapPin className="h-3 w-3" />{[s.venue, s.city].filter(Boolean).join(', ')}</p>}
      <div className="mt-1.5"><CrewStack s={s} /></div>
      {large && needsAttention(s) && <p className="mt-1 text-2xs text-amber-700 dark:text-amber-300">Some crew have not confirmed yet</p>}
    </button>
  );
}

/* ---------------- Directory ---------------- */
function DirectoryTab({ onEdit }: { onEdit: (c: Partial<CrewMember>) => void }) {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [f, setF] = useUrlState({ q: '', category: '', availability: '', city: '' });
  const dq = useDebounce(f.q, 250);
  const params = { q: dq, category: f.category, availability: f.availability, city: f.city };
  const q = useQuery({ queryKey: ['crew', 'list', params], queryFn: () => api.get<{ items: CrewMember[]; cities: string[] }>('crew', params), placeholderData: keepPreviousData });
  const list = q.data?.items || [];
  const filtered = !!(f.q || f.category || f.availability || f.city);
  const money = can('crew.payments');

  const remove = async (c: CrewMember, cancelUpcoming = false) => {
    if (!cancelUpcoming && !(await confirm({ title: `Remove ${c.name}?`, message: 'They are removed from the directory. Past assignments and payments stay in your records.', confirmLabel: 'Remove', danger: true }))) return;
    try {
      await api.del(`crew/${c.id}${cancelUpcoming ? '?cancel_upcoming=1' : ''}`);
      qc.invalidateQueries({ queryKey: ['crew'] });
      qc.invalidateQueries({ queryKey: ['crew-schedules'] });
      toast(`${c.name} removed`);
    } catch (e) {
      if (e instanceof ApiError && e.code === 'has_upcoming') {
        if (await confirm({ title: 'Cancel upcoming assignments?', message: e.message, confirmLabel: 'Cancel them and remove', danger: true })) remove(c, true);
      } else toast(errMsg(e), 'error');
    }
  };

  return (
    <>
      <Card className="mb-3 p-3">
        <div className="flex flex-wrap gap-2">
          <SearchInput value={f.q} onChange={(v) => setF({ q: v })} placeholder="Search name, phone, city, skill…" className="w-full sm:w-64" />
          <Select aria-label="Category" className="w-auto" value={f.category} onChange={(e) => setF({ category: e.target.value })} options={config.crew_categories} placeholder="All categories" />
          <Select aria-label="Availability" className="w-auto" value={f.availability} onChange={(e) => setF({ availability: e.target.value })} options={config.crew_availability} placeholder="Any availability" />
          <Select aria-label="City" className="w-auto" value={f.city} onChange={(e) => setF({ city: e.target.value })} options={(q.data?.cities || []).map((c) => [c, c] as [string, string])} placeholder="All cities" />
          {filtered && <Button variant="ghost" size="sm" className="h-9" onClick={() => setF({ q: '', category: '', availability: '', city: '' })}>Clear</Button>}
        </div>
      </Card>
      {q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.isLoading ? (
        <Card><TableSkeleton /></Card>
      ) : !list.length ? (
        <Card>
          <EmptyState
            icon={<UsersRound className="h-5 w-5" />}
            title={filtered ? 'No crew match these filters' : 'No crew members yet'}
            description={!filtered && 'Add the freelancers you hire for shoots: second shooters, drone operators, editors and more.'}
            action={can('crew.edit') && !filtered && <Button variant="primary" onClick={() => onEdit({})}>Add your first crew member</Button>}
          />
        </Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {list.map((c) => (
            <Card key={c.id} className="p-4" data-testid="crew-card">
              <div className="flex items-start gap-3">
                <Avatar name={c.name} src={c.photo_url} size="lg" />
                <div className="min-w-0 flex-1">
                  <Link to={`/crew/members/${c.id}`} className="block truncate font-medium hover:text-accent-ink">{c.name}</Link>
                  <p className="truncate text-xs text-muted">{config.crew_categories[c.category] || c.category}{c.specialization ? ` · ${c.specialization}` : ''}</p>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    <Badge tone={crewAvailTone[c.availability]} dot>{config.crew_availability[c.availability]}</Badge>
                    {c.city && <Badge>{c.city}</Badge>}
                  </div>
                </div>
                {can('crew.edit') && (
                  <Menu trigger={() => <IconButton label="More"><MoreHorizontal className="h-4 w-4" /></IconButton>}>
                    {(close) => (
                      <>
                        <MenuItem icon={<Pencil />} onClick={() => (close(), onEdit(c))}>Edit</MenuItem>
                        <MenuItem icon={<Trash2 />} danger onClick={() => (close(), remove(c))}>Remove</MenuItem>
                      </>
                    )}
                  </Menu>
                )}
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                <a href={telLink(c.phone)} className="inline-flex items-center gap-1 hover:text-accent-ink"><Phone className="h-3 w-3" />{phoneDisplay(c.phone)}</a>
                {money && c.daily_rate != null && <span>{inr(c.daily_rate)}/day</span>}
                <span>{c.upcoming ? `${c.upcoming} upcoming` : 'No upcoming events'}</span>
              </div>
              <div className="mt-3 flex gap-2">
                <WaIcon href={crewWa(c)} />
                <Link to={`/crew/members/${c.id}`} className="inline-flex h-8 items-center rounded-lg border border-line bg-surface px-2.5 text-xs font-medium shadow-sm hover:bg-subtle">View profile</Link>
              </div>
            </Card>
          ))}
        </div>
      )}
    </>
  );
}

