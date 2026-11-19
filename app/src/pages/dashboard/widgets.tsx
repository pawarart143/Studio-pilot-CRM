import { Link, useNavigate } from 'react-router-dom';
import { Bar, BarChart, CartesianGrid, Cell, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AlertTriangle, BadgeCheck, CalendarClock, CheckCircle2, IndianRupee, ListTodo, Package, Percent, Receipt, TrendingUp, Wallet } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { dayjs, fmtDate, fmtTime, inr, monthLabel } from '@/lib/format';
import { deliverableTone, payLabel, payTone, series, stageColor } from '@/lib/tones';
import { Avatar, Badge, Card, CardHeader, EmptyState, ErrorState, Skeleton, StatCard } from '@/components/ui';
import { axisProps, ChartTooltip, gridProps, Legend, moneyTick, useIsDark } from '@/components/charts';
import { rangeLabel, useWidgetData, type LayoutItem, type WidgetMeta } from './layout';

/** Compact error card for a KPI that failed to load. */
export function StatError({ label, onRetry }: { label: string; onRetry: () => void }) {
  return (
    <div className="card flex items-start gap-3 p-4">
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300">
        <AlertTriangle className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-xs font-medium text-muted">{label}</span>
        <span className="mt-0.5 block text-sm">Couldn't load</span>
        <button type="button" onClick={onRetry} className="mt-0.5 text-xs font-medium text-accent-ink">
          Try again
        </button>
      </span>
    </div>
  );
}

function CardBody({ w, children, className = 'p-4', skeleton = 'h-48' }: { w: ReturnType<typeof useWidgetData>; children: () => JSX.Element; className?: string; skeleton?: string }) {
  if (w.error) return <ErrorState error={w.error} onRetry={() => w.refetch()} />;
  if (w.loading || !w.data)
    return (
      <div className={className}>
        <Skeleton className={skeleton} />
      </div>
    );
  return children();
}

/** Widgets that are not part of the shipped default dashboard. */
export function ExtraWidget({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  if (m.kind === 'stat') return <ExtraStat m={m} it={it} />;
  switch (m.id) {
    case 'chart_leads_by_stage':
      return <LeadsByStage m={m} it={it} />;
    case 'chart_cashflow':
      return <Cashflow m={m} it={it} />;
    case 'list_outstanding':
      return <Outstanding m={m} it={it} />;
    case 'list_deliverables':
      return <DeliverablesList m={m} it={it} />;
    case 'list_crew_schedule':
      return <CrewSchedule m={m} it={it} />;
    case 'chart_task_load':
      return <TaskLoad m={m} it={it} />;
    default:
      return null;
  }
}

function ExtraStat({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const nav = useNavigate();
  const { can } = useAuth();
  const w = useWidgetData(m, it);
  if (w.error) return <StatError label={m.title} onRetry={w.refetch} />;
  const x = w.data;
  const rl = rangeLabel(m, it.range);
  const wait = '…';
  switch (m.id) {
    case 'stat_conversion_rate':
      return <StatCard label="Conversion rate" value={x ? `${x.rate}%` : wait} sub={x ? `${x.booked} of ${x.leads} leads booked · ${rl}` : rl} icon={<Percent className="h-4 w-4" />} tone="green" onClick={can('reports.view') ? () => nav('/reports') : undefined} />;
    case 'stat_bookings_confirmed':
      return <StatCard label="Bookings confirmed" value={x ? x.count : wait} sub={x && x.value !== undefined ? `${inr(x.value)} · ${rl}` : rl} icon={<BadgeCheck className="h-4 w-4" />} tone="indigo" onClick={() => nav('/bookings')} />;
    case 'stat_collected':
      return <StatCard label="Payments received" value={x ? inr(x.amount) : wait} sub={rl} icon={<IndianRupee className="h-4 w-4" />} tone="green" onClick={() => nav('/payments?tab=transactions')} />;
    case 'stat_expenses':
      return <StatCard label="Expenses" value={x ? inr(x.total) : wait} sub={x ? `incl. ${inr(x.crew)} crew · ${rl}` : rl} icon={<Receipt className="h-4 w-4" />} tone="orange" onClick={() => nav('/payments?tab=expenses')} />;
    case 'stat_profit':
      return <StatCard label="Net (collected − expenses)" value={x ? inr(x.net) : wait} sub={x ? `${inr(x.collected)} in · ${inr(x.expenses)} out · ${rl}` : rl} icon={<TrendingUp className="h-4 w-4" />} tone={x && x.net < 0 ? 'red' : 'cyan'} onClick={can('reports.view') ? () => nav('/reports') : undefined} />;
    case 'stat_deliverables_open':
      return <StatCard label="Deliverables in progress" value={x ? x.count : wait} sub={x ? `${x.overdue} overdue` : undefined} icon={<Package className="h-4 w-4" />} tone="violet" onClick={() => nav('/deliverables')} />;
    case 'stat_tasks_open':
      return <StatCard label="Open tasks" value={x ? x.count : wait} sub={x ? `${x.overdue} overdue` : undefined} icon={<ListTodo className="h-4 w-4" />} tone="amber" onClick={() => nav('/tasks')} />;
    default:
      return null;
  }
}

function LeadsByStage({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const w = useWidgetData(m, it);
  const total = (w.data?.chart || []).reduce((s: number, r: any) => s + r.leads, 0);
  return (
    <Card>
      <CardHeader title="Leads by stage" subtitle={rangeLabel(m, it.range)} actions={<Link to="/pipeline" className="text-xs font-medium text-accent-ink">Pipeline</Link>} />
      <CardBody w={w} skeleton="h-64">
        {() =>
          total === 0 ? (
            <EmptyState title="No leads in this period" />
          ) : (
            <div className="h-72 px-2 pb-3 pt-3">
              <ResponsiveContainer>
                <BarChart data={w.data.chart} layout="vertical" margin={{ left: 4, right: 16 }}>
                  <CartesianGrid {...gridProps} horizontal={false} vertical />
                  <XAxis type="number" allowDecimals={false} {...axisProps} />
                  <YAxis type="category" dataKey="label" {...axisProps} width={112} />
                  <Tooltip content={<ChartTooltip />} cursor={{ fill: 'rgb(var(--subtle))' }} />
                  <Bar dataKey="leads" name="Leads" radius={[0, 4, 4, 0]} maxBarSize={16}>
                    {w.data.chart.map((r: any) => (
                      <Cell key={r.stage} fill={stageColor[r.stage] || '#94a3b8'} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          )
        }
      </CardBody>
    </Card>
  );
}

function Cashflow({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const dark = useIsDark();
  const w = useWidgetData(m, it);
  return (
    <Card className="xl:col-span-2">
      <CardHeader title="Income vs expenses" subtitle={`Payments received vs. expenses incl. crew, ${rangeLabel(m, it.range).toLowerCase()}`} actions={<Legend items={[{ label: 'Received', color: series(0, dark) }, { label: 'Expenses', color: series(4, dark) }, { label: 'Net', color: series(2, dark) }]} />} />
      <CardBody w={w} skeleton="h-64">
        {() => (
          <div className="h-72 px-2 pb-3 pt-4">
            <ResponsiveContainer>
              <ComposedChart data={w.data.chart} margin={{ left: 4, right: 12 }} barGap={2}>
                <CartesianGrid {...gridProps} />
                <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
                <YAxis tickFormatter={moneyTick} {...axisProps} width={52} />
                <Tooltip content={<ChartTooltip money labelFormat={monthLabel} />} cursor={{ fill: 'rgb(var(--subtle))' }} />
                <Bar dataKey="collected" name="Received" fill={series(0, dark)} radius={[4, 4, 0, 0]} maxBarSize={18} />
                <Bar dataKey="expenses" name="Expenses" fill={series(4, dark)} radius={[4, 4, 0, 0]} maxBarSize={18} />
                <Line dataKey="net" name="Net" stroke={series(2, dark)} strokeWidth={2} dot={false} type="monotone" />
              </ComposedChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

function Outstanding({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const w = useWidgetData(m, it);
  return (
    <Card>
      <CardHeader title="Outstanding payments" subtitle={w.data ? `${inr(w.data.total)} due across ${w.data.count} booking${w.data.count === 1 ? '' : 's'}` : 'Largest amounts due first'} actions={<Link to="/payments" className="text-xs font-medium text-accent-ink">Payments</Link>} />
      <CardBody w={w} className="p-5" skeleton="h-40">
        {() =>
          !w.data.items.length ? (
            <EmptyState icon={<CheckCircle2 className="h-5 w-5" />} title="Nothing outstanding" description="Every booking is fully paid." />
          ) : (
            <ul className="divide-y divide-line">
              {w.data.items.map((b: any) => (
                <li key={b.id}>
                  <Link to={`/bookings/${b.id}`} className="flex items-center gap-3 px-5 py-3 hover:bg-subtle">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{b.client_name}</p>
                      <p className="truncate text-xs text-muted">
                        {b.number}
                        {b.next_due?.due_date ? ` · next due ${fmtDate(b.next_due.due_date)}` : ''}
                      </p>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className={'tabular text-sm font-semibold ' + (b.status === 'overdue' ? 'text-red-600 dark:text-red-400' : '')}>{inr(b.pending)}</p>
                      <Badge tone={payTone[b.status] || 'gray'}>{payLabel[b.status] || b.status}</Badge>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )
        }
      </CardBody>
    </Card>
  );
}

function DeliverablesList({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const { config } = useAuth();
  const w = useWidgetData(m, it);
  const today = dayjs().format('YYYY-MM-DD');
  return (
    <Card>
      <CardHeader title="Deliverables in progress" subtitle="Soonest expected date first" actions={<Link to="/deliverables" className="text-xs font-medium text-accent-ink">All deliverables</Link>} />
      <CardBody w={w} className="p-5" skeleton="h-40">
        {() =>
          !w.data.items.length ? (
            <EmptyState icon={<CheckCircle2 className="h-5 w-5" />} title="Nothing in progress" description="Open deliverables will appear here." />
          ) : (
            <ul className="divide-y divide-line">
              {w.data.items.map((x: any) => {
                const late = x.expected_date && x.expected_date < today;
                return (
                  <li key={x.id}>
                    <Link to={`/bookings/${x.booking_id}`} className="block px-5 py-3 hover:bg-subtle">
                      <div className="flex items-center gap-2">
                        <p className="min-w-0 flex-1 truncate text-sm font-medium">{x.name}</p>
                        <Badge tone={deliverableTone[x.status] || 'gray'}>{config.deliverable_statuses[x.status] || x.status}</Badge>
                      </div>
                      <p className="mt-0.5 truncate text-xs text-muted">
                        {x.client_name} · {x.booking_number}
                        {x.assigned_name ? ` · ${x.assigned_name}` : ''}
                        {' · '}
                        <span className={late ? 'font-medium text-red-600 dark:text-red-400' : ''}>{x.expected_date ? (late ? 'Overdue, ' : 'Due ') + fmtDate(x.expected_date) : 'No date'}</span>
                      </p>
                      <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-subtle">
                        <div className="h-full rounded-full bg-accent" style={{ width: `${Math.min(100, Math.max(0, x.progress || 0))}%` }} />
                      </div>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )
        }
      </CardBody>
    </Card>
  );
}

function CrewSchedule({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const { config } = useAuth();
  const w = useWidgetData(m, it);
  const groups: Record<string, any[]> = {};
  (w.data?.items || []).forEach((a: any) => (groups[a.event_date] = groups[a.event_date] || []).push(a));
  return (
    <Card>
      <CardHeader title="Crew schedule" subtitle={rangeLabel(m, it.range)} actions={<Link to="/crew" className="text-xs font-medium text-accent-ink">Crew</Link>} />
      <CardBody w={w} className="p-5" skeleton="h-40">
        {() =>
          !w.data.items.length ? (
            <EmptyState icon={<CalendarClock className="h-5 w-5" />} title="No crew booked" description="Scheduled freelancers will appear here." />
          ) : (
            <div className="max-h-[420px] divide-y divide-line overflow-y-auto">
              {Object.entries(groups).map(([date, rows]) => (
                <div key={date} className="px-5 py-3">
                  <p className="text-2xs font-semibold uppercase text-muted">{dayjs(date).format('ddd, DD-MM-YYYY')}</p>
                  <ul className="mt-1.5 space-y-1.5">
                    {rows.map((a) => (
                      <li key={a.id} className="flex items-start gap-2.5">
                        <Avatar name={a.crew_name} size="sm" />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">
                            {a.crew_name} <span className="font-normal text-muted">· {config.crew_categories[a.role] || a.role}</span>
                          </p>
                          <p className="truncate text-xs text-muted">
                            {a.event_name}
                            {a.client_name ? ` · ${a.client_name}` : ''}
                            {a.reporting_time || a.start_time ? ` · ${fmtTime(a.reporting_time || a.start_time)}` : ''}
                            {a.venue || a.city ? ` · ${[a.venue, a.city].filter(Boolean).join(', ')}` : ''}
                          </p>
                        </div>
                        <Badge tone={a.status === 'confirmed' ? 'green' : a.status === 'confirmation_pending' ? 'amber' : 'gray'}>{config.crew_assignment_statuses[a.status] || a.status}</Badge>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )
        }
      </CardBody>
    </Card>
  );
}

function TaskLoad({ m, it }: { m: WidgetMeta; it: LayoutItem }) {
  const dark = useIsDark();
  const w = useWidgetData(m, it);
  return (
    <Card>
      <CardHeader title="Task load by person" subtitle="Open tasks, with overdue" actions={<Legend items={[{ label: 'Open', color: series(0, dark) }, { label: 'Overdue', color: series(4, dark) }]} />} />
      <CardBody w={w} skeleton="h-56">
        {() =>
          !w.data.chart.length ? (
            <EmptyState icon={<Wallet className="h-5 w-5" />} title="No open tasks" />
          ) : (
            <div className="px-2 pb-3 pt-3" style={{ height: Math.max(160, w.data.chart.length * 34 + 40) }}>
              <ResponsiveContainer>
                <BarChart data={w.data.chart} layout="vertical" margin={{ left: 4, right: 16 }} barGap={2}>
                  <CartesianGrid {...gridProps} horizontal={false} vertical />
                  <XAxis type="number" allowDecimals={false} {...axisProps} />
                  <YAxis type="category" dataKey="name" {...axisProps} width={96} />
                  <Tooltip content={<ChartTooltip />} cursor={{ fill: 'rgb(var(--subtle))' }} />
                  <Bar dataKey="open" name="Open" fill={series(0, dark)} radius={[0, 4, 4, 0]} maxBarSize={12} />
                  <Bar dataKey="overdue" name="Overdue" fill={series(4, dark)} radius={[0, 4, 4, 0]} maxBarSize={12} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )
        }
      </CardBody>
    </Card>
  );
}

