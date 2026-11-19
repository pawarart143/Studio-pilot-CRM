import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bar, BarChart, CartesianGrid, Cell, Line, ComposedChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { LayoutGrid, CalendarDays, CheckCircle2, Circle, CreditCard, IndianRupee, MessageCircle, Phone, PhoneCall, Sparkles, UserPlus, Users, XCircle, FileText, Handshake, BadgeCheck, Clock, Heart } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDateShort, fmtTime, inr, inrShort, monthLabel, dayjs, waLink, telLink, fromNow } from '@/lib/format';
import { eventColor, series, sourceColor } from '@/lib/tones';
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, PageHeader, Skeleton, StatCard, useUi } from '@/components/ui';
import { axisProps, ChartTooltip, gridProps, Legend, moneyTick, useIsDark } from '@/components/charts';
import { Timeline } from '@/components/shared';
import { FALLBACK, rangeLabel, useLayout, useWidgetData, usesMain, type LayoutItem, type WidgetMeta } from './dashboard/layout';
import { CustomizeDrawer } from './dashboard/CustomizeDrawer';
import { ExtraWidget, StatError } from './dashboard/widgets';

export default function Dashboard() {
  const { can, me, studio } = useAuth();
  const [sp] = useSearchParams();
  const q = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get('dashboard') });
  const lay = useLayout();
  const [draft, setDraft] = useState<LayoutItem[] | null>(null);
  const d = q.data;
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  // Widgets this member may use, with the layout to show (the draft while customizing).
  const metas: Record<string, WidgetMeta> = {};
  let items: LayoutItem[] = [];
  if (lay.data) {
    lay.data.widgets.forEach((w) => (metas[w.id] = w));
    items = draft || lay.data.items;
  } else if (lay.error) {
    FALLBACK.filter((f) => can(f.perm)).forEach((f) => {
      metas[f.meta.id] = f.meta;
      items.push({ id: f.meta.id, visible: true, range: f.meta.default_range });
    });
  }
  const shown = items.filter((it) => it.visible && metas[it.id]);
  const mainFailed = !!q.error;
  const rendered = shown.filter((it) => !(mainFailed && usesMain(metas[it.id], it)));

  // Consecutive KPI cards of the same strip share one grid; consecutive panels share the 3-column grid.
  const segments: { type: 'strip' | 'cards'; strip?: string; items: LayoutItem[] }[] = [];
  rendered.forEach((it) => {
    const m = metas[it.id];
    const last = segments[segments.length - 1];
    if (m.kind === 'stat') {
      if (last && last.type === 'strip' && last.strip === m.strip) last.items.push(it);
      else segments.push({ type: 'strip', strip: m.strip || 'insights', items: [it] });
    } else if (last && last.type === 'cards') last.items.push(it);
    else segments.push({ type: 'cards', items: [it] });
  });

  return (
    <div>
      <PageHeader
        title={`${greet}, ${me.user?.name.split(' ')[0]}`}
        subtitle={`Here's what's happening at ${studio.name} today, ${dayjs().format('dddd, DD MMMM')}.`}
        actions={lay.data && <Button size="sm" icon={<LayoutGrid className="h-4 w-4" />} onClick={() => setDraft(lay.data!.items.map((x) => ({ ...x })))}>Customize</Button>}
      />
      {sp.get('welcome') && d && d.total_leads === 0 && <WelcomeCard />}

      {mainFailed && (
        <Card className="mb-5">
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        </Card>
      )}

      {lay.isLoading ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
          {Array.from({ length: 10 }).map((_, i) => <Skeleton key={i} className="h-[76px] rounded-xl" />)}
        </div>
      ) : rendered.length === 0 && !mainFailed ? (
        <Card>
          <EmptyState icon={<LayoutGrid className="h-5 w-5" />} title="Your dashboard is empty" description="Add widgets to track what matters to you." action={lay.data && <Button variant="primary" size="sm" onClick={() => setDraft(lay.data!.items.map((x) => ({ ...x })))}>Customize</Button>} />
        </Card>
      ) : (
        segments.map((seg, i) => {
          const gap = i === 0 ? '' : seg.type === 'strip' && segments[i - 1].type === 'strip' ? 'mt-3 ' : 'mt-5 ';
          if (seg.type === 'cards')
            return (
              <div key={i} className={gap + 'grid grid-cols-1 gap-5 xl:grid-cols-3'}>
                {seg.items.map((it) => <Widget key={it.id} m={metas[it.id]} it={it} d={d} />)}
              </div>
            );
          return (
            <div key={i} className={gap + STRIP_CLASS[seg.strip || 'insights']}>
              {seg.items.map((it) => <Widget key={it.id} m={metas[it.id]} it={it} d={d} />)}
            </div>
          );
        })
      )}

      {draft && lay.data && <CustomizeDrawer widgets={lay.data.widgets} isDefault={lay.data.is_default} draft={draft} setDraft={setDraft} onClose={() => setDraft(null)} />}
    </div>
  );
}

const STRIP_CLASS: Record<string, string> = {
  pipeline: 'grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5',
  business: 'grid grid-cols-1 gap-3 sm:grid-cols-3',
  insights: 'grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4',
};

/** One widget, by id. Shipped widgets render exactly as on the v0.5.0 dashboard. */
function Widget({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const nav = useNavigate();
  const go = (stage: string) => nav(`/leads?stage=${stage}`);
  const st = d?.stages || {};
  const pipe = (node: (d: any) => JSX.Element) => (!d ? <Skeleton className="h-[76px] rounded-xl" /> : node(d));
  switch (m.id) {
    case 'stat_total_leads':
      return pipe((d) => <StatCard label="Total Leads" value={d.total_leads} sub={`${d.new_this_month} added this month`} icon={<Users className="h-4 w-4" />} tone="indigo" onClick={() => nav('/leads')} />);
    case 'stat_new_leads':
      return pipe(() => <StatCard label="New Leads" value={st.new} sub="Not contacted yet" icon={<UserPlus className="h-4 w-4" />} tone="blue" onClick={() => go('new')} />);
    case 'stat_contacted':
      return pipe(() => <StatCard label="Contacted" value={st.contacted + st.first_call_pending} sub={`${st.first_call_pending} first call pending`} icon={<PhoneCall className="h-4 w-4" />} tone="cyan" onClick={() => go('first_call_pending,contacted')} />);
    case 'stat_followups_pending':
      return pipe((d) => <StatCard label="Follow-up Pending" value={d.followups_pending} sub="Due today or overdue" icon={<Clock className="h-4 w-4" />} tone="amber" onClick={() => nav('/calling')} />);
    case 'stat_interested':
      return pipe(() => <StatCard label="Interested" value={st.interested + st.followup_required} sub={`${st.followup_required} need follow-up`} icon={<Heart className="h-4 w-4" />} tone="violet" onClick={() => go('interested,followup_required')} />);
    case 'stat_quotation_sent':
      return pipe(() => <StatCard label="Quotation Sent" value={st.quotation_sent} icon={<FileText className="h-4 w-4" />} tone="orange" onClick={() => go('quotation_sent')} />);
    case 'stat_negotiation':
      return pipe(() => <StatCard label="Negotiation" value={st.negotiation} icon={<Handshake className="h-4 w-4" />} tone="pink" onClick={() => go('negotiation')} />);
    case 'stat_booked':
      return pipe(() => <StatCard label="Booking Confirmed" value={st.booked} icon={<BadgeCheck className="h-4 w-4" />} tone="green" onClick={() => go('booked')} />);
    case 'stat_lost':
      return pipe(() => <StatCard label="Lost Leads" value={st.lost} icon={<XCircle className="h-4 w-4" />} tone="gray" onClick={() => go('lost')} />);
    case 'stat_followups_today':
      return pipe((d) => <StatCard label="Today's Follow-ups" value={d.followups_today?.filter((f: any) => dayjs(f.next_followup_at).isSame(dayjs(), 'day')).length ?? 0} sub="Scheduled for today" icon={<Phone className="h-4 w-4" />} tone="red" onClick={() => nav('/calling')} />);
    case 'stat_revenue':
      return <StatCard label="Total Revenue (collected)" value={d ? inr(d.total_revenue) : '…'} sub={d ? `${inr(d.revenue_month)} this month` : undefined} icon={<IndianRupee className="h-4 w-4" />} tone="green" onClick={() => nav('/payments?tab=transactions')} />;
    case 'stat_pending_payments':
      return <StatCard label="Pending Payments" value={d ? inr(d.pending_payments) : '…'} sub={d ? `${d.overdue_bookings} bookings overdue` : undefined} icon={<CreditCard className="h-4 w-4" />} tone="red" onClick={() => nav('/payments')} />;
    case 'stat_upcoming_events':
      return <UpcomingStat m={m} it={it} d={d} />;
    case 'chart_revenue':
      return <RevenueChart m={m} it={it} d={d} />;
    case 'chart_sources':
      return <SourcesCard m={m} it={it} d={d} />;
    case 'chart_conversion':
      return <ConversionChart m={m} it={it} d={d} />;
    case 'list_followups':
      return <FollowupsToday items={d?.followups_today} loading={!d} />;
    case 'list_upcoming_events':
      return <UpcomingEventsWidget m={m} it={it} d={d} />;
    case 'list_tasks':
      return <TodayTasks tasks={d?.tasks_today} loading={!d} />;
    case 'list_activity':
      return (
        <Card>
          <CardHeader title="Recent activity" actions={<Link to="/leads" className="text-xs font-medium text-accent-ink">View leads</Link>} />
          <div className="max-h-[420px] overflow-y-auto p-5">{!d ? <Skeleton className="h-40" /> : <Timeline items={d.activities} />}</div>
        </Card>
      );
    default:
      return <ExtraWidget m={m} it={it} />;
  }
}

function UpcomingStat({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const nav = useNavigate();
  const w = useWidgetData(m, it);
  if (w.error) return <StatError label="Upcoming Events" onRetry={w.refetch} />;
  const value = w.own ? (w.data ? w.data.count : '…') : d ? d.upcoming_count : '…';
  return <StatCard label="Upcoming Events" value={value} sub={w.own ? rangeLabel(m, it.range) : 'Next 30 days'} icon={<CalendarDays className="h-4 w-4" />} tone="violet" onClick={() => nav('/calendar')} />;
}

function RevenueChart({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const dark = useIsDark();
  const w = useWidgetData(m, it);
  const data = w.own ? w.data?.chart : d?.revenue_chart;
  const months = parseInt(it.range || '12', 10);
  return (
    <Card className="xl:col-span-2">
      <CardHeader title="Monthly revenue" subtitle={`Payments collected vs. new bookings value, last ${months} months`} actions={<Legend items={[{ label: 'Collected', color: series(0, dark) }, { label: 'Booked value', color: series(1, dark) }]} />} />
      <div className="h-72 px-2 pb-3 pt-4">
        {w.error ? (
          <ErrorState error={w.error} onRetry={() => w.refetch()} />
        ) : !data ? (
          <Skeleton className="mx-3 h-full" />
        ) : (
          <ResponsiveContainer>
            <ComposedChart data={data} margin={{ left: 4, right: 12 }}>
              <CartesianGrid {...gridProps} />
              <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
              <YAxis tickFormatter={moneyTick} {...axisProps} width={52} />
              <Tooltip content={<ChartTooltip money labelFormat={monthLabel} />} cursor={{ fill: 'rgb(var(--subtle))' }} />
              <Bar dataKey="collected" name="Collected" fill={series(0, dark)} radius={[4, 4, 0, 0]} maxBarSize={28} />
              <Line dataKey="booked" name="Booked value" stroke={series(1, dark)} strokeWidth={2} dot={{ r: 4, strokeWidth: 2, stroke: 'rgb(var(--surface))', fill: series(1, dark) }} activeDot={{ r: 5 }} type="monotone" />
            </ComposedChart>
          </ResponsiveContainer>
        )}
      </div>
    </Card>
  );
}

function SourcesCard({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const dark = useIsDark();
  const { config } = useAuth();
  const w = useWidgetData(m, it);
  const data = w.own ? w.data?.chart : d?.source_chart;
  return (
    <Card>
      <CardHeader title="Lead sources" subtitle={!w.own || it.range === 'all' ? 'All-time enquiries by channel' : `Enquiries by channel, ${rangeLabel(m, it.range).toLowerCase()}`} />
      <div className="p-4">
        {w.error ? (
          <ErrorState error={w.error} onRetry={() => w.refetch()} />
        ) : !data ? (
          <Skeleton className="h-60" />
        ) : data.length === 0 ? (
          <EmptyState title="No leads yet" />
        ) : (
          <SourceDonut data={data} dark={dark} labels={config.sources} />
        )}
      </div>
    </Card>
  );
}

function ConversionChart({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const dark = useIsDark();
  const w = useWidgetData(m, it);
  const data = w.own ? w.data?.chart : d?.conversion_chart;
  return (
    <Card className="xl:col-span-2">
      <CardHeader title="Lead conversion" subtitle={'Leads received each month and how many became bookings' + (w.own ? `, ${rangeLabel(m, it.range).toLowerCase()}` : '')} actions={<Legend items={[{ label: 'Leads', color: series(0, dark) }, { label: 'Booked', color: series(2, dark) }]} />} />
      <div className="h-64 px-2 pb-3 pt-4">
        {w.error ? (
          <ErrorState error={w.error} onRetry={() => w.refetch()} />
        ) : !data ? (
          <Skeleton className="mx-3 h-full" />
        ) : (
          <ResponsiveContainer>
            <BarChart data={data} margin={{ left: 0, right: 12 }} barGap={2}>
              <CartesianGrid {...gridProps} />
              <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
              <YAxis allowDecimals={false} {...axisProps} width={32} />
              <Tooltip content={<ChartTooltip labelFormat={monthLabel} />} cursor={{ fill: 'rgb(var(--subtle))' }} />
              <Bar dataKey="leads" name="Leads" fill={series(0, dark)} radius={[4, 4, 0, 0]} maxBarSize={18} />
              <Bar dataKey="booked" name="Booked" fill={series(2, dark)} radius={[4, 4, 0, 0]} maxBarSize={18} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>
    </Card>
  );
}

function UpcomingEventsWidget({ m, it, d }: { m: WidgetMeta; it: LayoutItem; d: any }) {
  const w = useWidgetData(m, it);
  if (!w.own) return <UpcomingEvents events={d?.upcoming_events} loading={!d} />;
  if (w.error)
    return (
      <Card className="xl:col-span-2">
        <CardHeader title="Upcoming weddings & events" subtitle={rangeLabel(m, it.range)} />
        <ErrorState error={w.error} onRetry={() => w.refetch()} />
      </Card>
    );
  return <UpcomingEvents events={w.data?.events} loading={w.loading || !w.data} subtitle={rangeLabel(m, it.range)} />;
}

function SourceDonut({ data, dark, labels }: { data: any[]; dark: boolean; labels: Record<string, string> }) {
  const total = data.reduce((s, r) => s + r.leads, 0);
  return (
    <div>
      <div className="relative h-48">
        <ResponsiveContainer>
          <PieChart>
            <Pie data={data} dataKey="leads" nameKey="source" innerRadius="62%" outerRadius="92%" paddingAngle={1.5} stroke="rgb(var(--surface))" strokeWidth={2}>
              {data.map((r) => (
                <Cell key={r.source} fill={sourceColor(r.source, dark)} />
              ))}
            </Pie>
            <Tooltip content={({ active, payload }: any) => (active && payload?.length ? <div className="rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-pop"><p className="font-semibold">{labels[payload[0].payload.source]}</p><p className="text-muted">{payload[0].payload.leads} leads · {payload[0].payload.booked} booked</p></div> : null)} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <span className="tabular text-2xl font-semibold">{total}</span>
          <span className="text-xs text-muted">leads</span>
        </div>
      </div>
      <ul className="mt-3 space-y-1.5">
        {data.map((r) => (
          <li key={r.source} className="flex items-center gap-2 text-sm">
            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: sourceColor(r.source, dark) }} />
            <span className="flex-1 truncate">{labels[r.source] || r.source}</span>
            <span className="tabular text-muted">{r.leads}</span>
            <span className="tabular w-12 text-right text-xs text-faint">{Math.round((r.leads * 100) / total)}%</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function FollowupsToday({ items, loading }: { items?: any[]; loading: boolean }) {
  const { config } = useAuth();
  return (
    <Card>
      <CardHeader title="Today's follow-ups" subtitle="Due today and overdue" actions={<Link to="/calling" className="text-xs font-medium text-accent-ink">Calling list</Link>} />
      {loading ? (
        <div className="p-5"><Skeleton className="h-40" /></div>
      ) : !items?.length ? (
        <EmptyState icon={<CheckCircle2 className="h-5 w-5" />} title="All caught up" description="No follow-ups due today." />
      ) : (
        <ul className="divide-y divide-line">
          {items.map((f) => {
            const overdue = dayjs(f.next_followup_at).isBefore(dayjs(), 'day');
            return (
              <li key={f.id} className="flex items-center gap-3 px-5 py-3">
                <div className="min-w-0 flex-1">
                  <Link to={`/leads/${f.id}`} className="block truncate text-sm font-medium hover:text-accent-ink">{f.name}</Link>
                  <p className="truncate text-xs text-muted">
                    {config.event_types[f.event_type] || 'Event'} · {fmtDateShort(f.event_date)} ·{' '}
                    <span className={overdue ? 'font-medium text-red-600' : ''}>{overdue ? 'Overdue ' + fromNow(f.next_followup_at) : fmtTime(f.next_followup_at.slice(11))}</span>
                  </p>
                </div>
                <a href={telLink(f.phone)} className="rounded-lg p-2 text-muted hover:bg-subtle hover:text-accent-ink" aria-label="Call"><Phone className="h-4 w-4" /></a>
                <a href={waLink(f.whatsapp || f.phone)} target="_blank" rel="noreferrer" className="rounded-lg p-2 text-muted hover:bg-subtle hover:text-green-600" aria-label="WhatsApp"><MessageCircle className="h-4 w-4" /></a>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function UpcomingEvents({ events, loading, subtitle = 'Next 60 days' }: { events?: any[]; loading: boolean; subtitle?: string }) {
  const { config } = useAuth();
  const groups: Record<string, any[]> = {};
  (events || []).forEach((e) => (groups[e.event_date] = groups[e.event_date] || []).push(e));
  return (
    <Card className="xl:col-span-2">
      <CardHeader title="Upcoming weddings & events" subtitle={subtitle} actions={<Link to="/calendar" className="text-xs font-medium text-accent-ink">Open calendar</Link>} />
      {loading ? (
        <div className="p-5"><Skeleton className="h-40" /></div>
      ) : !events?.length ? (
        <EmptyState icon={<CalendarDays className="h-5 w-5" />} title="No upcoming events" description="Confirmed booking dates will appear here." />
      ) : (
        <div className="max-h-[420px] divide-y divide-line overflow-y-auto">
          {Object.entries(groups).map(([date, evs]) => (
            <div key={date} className="flex gap-4 px-5 py-3">
              <div className="w-12 shrink-0 text-center">
                <p className="text-2xs font-semibold uppercase text-muted">{dayjs(date).format('MMM')}</p>
                <p className="text-xl font-semibold leading-tight">{dayjs(date).format('DD')}</p>
                <p className="text-2xs text-faint">{dayjs(date).format('ddd')}</p>
              </div>
              <div className="min-w-0 flex-1 space-y-2">
                {evs.map((e) => (
                  <Link key={e.id} to={`/bookings/${e.booking_id}`} className="flex items-start gap-2.5 rounded-lg p-1.5 hover:bg-subtle">
                    <span className="mt-1 h-8 w-1 shrink-0 rounded-full" style={{ background: eventColor[e.type] || eventColor.other }} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{e.name} · {e.client_name}</p>
                      <p className="truncate text-xs text-muted">
                        {e.start_time ? fmtTime(e.start_time) + ' · ' : ''}
                        {[e.venue, e.city].filter(Boolean).join(', ')}
                      </p>
                    </div>
                    <div className="hidden shrink-0 -space-x-1.5 sm:flex">
                      {e.team.length === 0 ? <Badge tone="red">No team</Badge> : e.team.map((t: any) => <span key={t.member_id} title={t.name} className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-semibold text-white ring-2 ring-surface" style={{ background: t.color }}>{t.name[0]}</span>)}
                    </div>
                    <Badge className="hidden md:inline-flex">{config.event_types[e.type] || 'Event'}</Badge>
                  </Link>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

function TodayTasks({ tasks, loading }: { tasks?: any[]; loading: boolean }) {
  const qc = useQueryClient();
  const { toast } = useUi();
  const done = useMutation({
    mutationFn: (id: number) => api.put(`tasks/${id}`, { status: 'done' }),
    onSuccess: () => (qc.invalidateQueries({ queryKey: ['dashboard'] }), qc.invalidateQueries({ queryKey: ['tasks'] }), toast('Task completed')),
    onError: (e: any) => toast(e.message, 'error'),
  });
  return (
    <Card>
      <CardHeader title="Today's tasks" subtitle="Due today and overdue" actions={<Link to="/tasks" className="text-xs font-medium text-accent-ink">All tasks</Link>} />
      {loading ? (
        <div className="p-5"><Skeleton className="h-40" /></div>
      ) : !tasks?.length ? (
        <EmptyState icon={<CheckCircle2 className="h-5 w-5" />} title="Nothing due today" />
      ) : (
        <ul className="divide-y divide-line">
          {tasks.map((t) => (
            <li key={t.id} className="flex items-start gap-3 px-5 py-3">
              <button onClick={() => done.mutate(t.id)} className="mt-0.5 text-faint hover:text-emerald-600" aria-label="Mark done"><Circle className="h-4 w-4" /></button>
              <div className="min-w-0 flex-1">
                <p className="text-sm">{t.title}</p>
                <p className={'text-xs ' + (dayjs(t.due_at).isBefore(dayjs(), 'day') ? 'text-red-600' : 'text-muted')}>
                  {dayjs(t.due_at).isBefore(dayjs(), 'day') ? 'Overdue · ' : ''}
                  {dayjs(t.due_at).format('DD MMM, hh:mm A')}
                  {t.assigned_name ? ' · ' + t.assigned_name : ''}
                </p>
              </div>
              {['high', 'hot'].includes(t.priority) && <Badge tone={t.priority === 'hot' ? 'red' : 'amber'}>{t.priority === 'hot' ? 'Hot' : 'High'}</Badge>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function WelcomeCard() {
  const { can } = useAuth();
  const qc = useQueryClient();
  const { toast } = useUi();
  const [busy, setBusy] = useState(false);
  const load = async () => {
    setBusy(true);
    try {
      await api.post('settings/sample-data');
      toast('Sample data loaded');
      qc.invalidateQueries();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  return (
    <Card className="mb-5 flex flex-col gap-4 bg-gradient-to-br from-accent-soft to-surface p-5 sm:flex-row sm:items-center">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent text-white dark:text-slate-950"><Sparkles className="h-5 w-5" /></span>
      <div className="flex-1">
        <p className="font-semibold">Welcome to your studio workspace!</p>
        <p className="text-sm text-muted">Add your first enquiry, or load realistic sample data (leads, bookings, payments) to explore every feature. You can reset it later from Settings.</p>
      </div>
      {can('settings.edit') && <Button variant="primary" loading={busy} onClick={load}>Load sample data</Button>}
    </Card>
  );
}

export { inrShort };
