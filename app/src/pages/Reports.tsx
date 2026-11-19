import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Download } from 'lucide-react';
import { api, saveBlob } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useUrlState } from '@/lib/hooks';
import { dayjs, fmtDate, inr, inrShort, monthLabel } from '@/lib/format';
import { payLabel, payTone, series, sourceColor } from '@/lib/tones';
import { Badge, Button, Card, CardHeader, ErrorState, Input, PageHeader, PageLoader, Segmented } from '@/components/ui';
import { ChartTooltip, Legend, axisProps, gridProps, moneyTick, useIsDark } from '@/components/charts';

/** Indian financial year starts 1 April. */
const fyStart = () => {
  const d = dayjs();
  return (d.month() >= 3 ? d : d.subtract(1, 'year')).month(3).date(1);
};
const PRESETS: Record<string, () => [string, string]> = {
  month: () => [dayjs().startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')],
  quarter: () => [dayjs().subtract(2, 'month').startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')],
  fy: () => [fyStart().format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')],
  year: () => [dayjs().subtract(11, 'month').startOf('month').format('YYYY-MM-DD'), dayjs().format('YYYY-MM-DD')],
};

function csv(name: string, header: string[], rows: (string | number | null | undefined)[][]) {
  const esc = (v: any) => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const body = [header, ...rows].map((r) => r.map(esc).join(',')).join('\n');
  saveBlob(new Blob(['﻿' + body], { type: 'text/csv' }), `${name}-${dayjs().format('DD-MM-YYYY')}.csv`);
}

export default function Reports() {
  const { config, studio } = useAuth();
  const dark = useIsDark();
  const [init] = [PRESETS.year()];
  const [f, setF] = useUrlState({ preset: 'year', from: init[0], to: init[1] });
  const q = useQuery({ queryKey: ['reports', f.from, f.to], queryFn: () => api.get('reports', { from: f.from, to: f.to }), placeholderData: keepPreviousData });
  const pick = (p: string) => {
    if (p === 'custom') return setF({ preset: p });
    const [from, to] = PRESETS[p]();
    setF({ preset: p, from, to });
  };

  if (!studio.plan_info.reports)
    return (
      <div>
        <PageHeader title="Reports" />
        <Card className="p-8 text-center"><p className="font-medium">Reports are not included in your plan.</p><p className="mt-1 text-sm text-muted">Upgrade from Settings → Plan to unlock sales, revenue and team reports.</p></Card>
      </div>
    );

  const d = q.data;
  const s = d?.summary;
  const period = `${fmtDate(f.from)} to ${fmtDate(f.to)}`;

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle={period}
        actions={
          <>
            <Segmented value={f.preset} onChange={pick} options={[['month', 'This month'], ['quarter', '3 months'], ['fy', 'This FY'], ['year', '12 months'], ['custom', 'Custom']]} />
            {f.preset === 'custom' && (
              <div className="flex items-center gap-2">
                <Input type="date" value={f.from} onChange={(e) => setF({ from: e.target.value })} className="w-auto py-1.5" aria-label="From" />
                <span className="text-xs text-muted">to</span>
                <Input type="date" value={f.to} onChange={(e) => setF({ to: e.target.value })} className="w-auto py-1.5" aria-label="To" />
              </div>
            )}
          </>
        }
      />
      {q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : !d ? (
        <PageLoader />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
            <Tile label="Leads" value={s.leads} />
            <Tile label="Booked" value={s.booked} sub={`${s.conversion}% conversion`} />
            <Tile label="Lost" value={s.lost} />
            <Tile label="Bookings value" value={inrShort(s.booked_value)} sub={`${s.bookings} bookings`} />
            <Tile label="Avg. booking" value={inrShort(s.avg_booking)} />
            <Tile label="Collected" value={inrShort(s.collected)} />
            <Tile label="Expenses" value={inrShort(s.expenses)} sub={s.crew_expenses ? `incl. crew ${inrShort(s.crew_expenses)}` : undefined} />
            <Tile label="Net (collected − expenses)" value={inrShort(s.profit)} />
          </div>

          <div className="grid gap-5 xl:grid-cols-2">
            <Section
              title="Monthly revenue"
              subtitle="Payments collected and expenses, by month"
              onCsv={() => csv('monthly-revenue', ['Month', 'Leads', 'Booked', 'Lost', 'Bookings', 'Bookings value', 'Collected', 'Expenses (all)', 'Crew payments'], d.monthly.map((m: any) => [monthLabel(m.month), m.leads, m.booked, m.lost, m.bookings, m.booked_value, m.collected, m.expenses, m.crew_expenses]))}
            >
              <div className="px-4"><Legend items={[{ label: 'Collected', color: series(0, dark) }, { label: 'Expenses', color: series(1, dark) }]} /></div>
              <div className="h-64 px-2 pb-3 pt-2">
                <ResponsiveContainer>
                  <BarChart data={d.monthly} barGap={2} margin={{ left: 4, right: 12 }}>
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
                    <YAxis tickFormatter={moneyTick} {...axisProps} width={52} />
                    <Tooltip content={<ChartTooltip money labelFormat={monthLabel} />} cursor={{ fill: 'rgb(var(--subtle))' }} />
                    <Bar dataKey="collected" name="Collected" fill={series(0, dark)} radius={[4, 4, 0, 0]} maxBarSize={22} />
                    <Bar dataKey="expenses" name="Expenses" fill={series(1, dark)} radius={[4, 4, 0, 0]} maxBarSize={22} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Section>
            <Section title="Leads vs bookings" subtitle="New leads by month and how many of them booked">
              <div className="px-4"><Legend items={[{ label: 'Leads', color: series(0, dark) }, { label: 'Booked', color: series(2, dark) }]} /></div>
              <div className="h-64 px-2 pb-3 pt-2">
                <ResponsiveContainer>
                  <BarChart data={d.monthly} barGap={2} margin={{ left: 0, right: 12 }}>
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
                    <YAxis allowDecimals={false} {...axisProps} width={32} />
                    <Tooltip content={<ChartTooltip labelFormat={monthLabel} />} cursor={{ fill: 'rgb(var(--subtle))' }} />
                    <Bar dataKey="leads" name="Leads" fill={series(0, dark)} radius={[4, 4, 0, 0]} maxBarSize={22} />
                    <Bar dataKey="booked" name="Booked" fill={series(2, dark)} radius={[4, 4, 0, 0]} maxBarSize={22} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Section>
          </div>

          <div className="grid gap-5 xl:grid-cols-2">
            <Section title="Lead sources" subtitle="Where enquiries come from and how well they convert" onCsv={() => csv('lead-sources', ['Source', 'Leads', 'Booked', 'Lost', 'Conversion %', 'Revenue'], d.sources.map((r: any) => [config.sources[r.source] || r.source, r.leads, r.booked, r.lost, r.conversion, r.revenue]))}>
              <Table head={['Source', 'Leads', 'Booked', 'Conv.', 'Revenue']}>
                {d.sources.map((r: any) => (
                  <tr key={r.source}>
                    <td className="td"><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: sourceColor(r.source, dark) }} />{config.sources[r.source] || r.source}</span></td>
                    <td className="td tabular text-right">{r.leads}</td>
                    <td className="td tabular text-right">{r.booked}</td>
                    <td className="td tabular text-right">{r.conversion}%</td>
                    <td className="td tabular text-right">{inrShort(r.revenue)}</td>
                  </tr>
                ))}
              </Table>
            </Section>
            <Section title="Event types" onCsv={() => csv('event-types', ['Event type', 'Leads', 'Booked', 'Conversion %', 'Revenue', 'Avg budget'], d.event_types.map((r: any) => [config.event_types[r.event_type] || r.event_type, r.leads, r.booked, r.conversion, r.revenue, Math.round(r.avg_budget)]))}>
              <Table head={['Event', 'Leads', 'Booked', 'Conv.', 'Revenue', 'Avg. budget']}>
                {d.event_types.map((r: any) => (
                  <tr key={r.event_type}>
                    <td className="td">{config.event_types[r.event_type] || r.event_type}</td>
                    <td className="td tabular text-right">{r.leads}</td>
                    <td className="td tabular text-right">{r.booked}</td>
                    <td className="td tabular text-right">{r.conversion}%</td>
                    <td className="td tabular text-right">{inrShort(r.revenue)}</td>
                    <td className="td tabular text-right">{r.avg_budget ? inrShort(r.avg_budget) : '—'}</td>
                  </tr>
                ))}
              </Table>
            </Section>
          </div>

          <Section title="Sales performance" subtitle="By the person each lead is assigned to" onCsv={() => csv('sales-performance', ['Member', 'Leads', 'Calls', 'Booked', 'Lost', 'Conversion %', 'Revenue'], d.sales.map((r: any) => [r.name, r.leads, r.calls, r.booked, r.lost, r.conversion, r.revenue]))}>
            <Table head={['Member', 'Leads', 'Calls', 'Booked', 'Lost', 'Conv.', 'Revenue']}>
              {d.sales.map((r: any) => (
                <tr key={r.member_id ?? 'none'}>
                  <td className="td font-medium">{r.name}</td>
                  <td className="td tabular text-right">{r.leads}</td>
                  <td className="td tabular text-right">{r.calls}</td>
                  <td className="td tabular text-right">{r.booked}</td>
                  <td className="td tabular text-right">{r.lost}</td>
                  <td className="td tabular text-right">{r.conversion}%</td>
                  <td className="td tabular text-right">{inrShort(r.revenue)}</td>
                </tr>
              ))}
            </Table>
          </Section>

          <div className="grid gap-5 xl:grid-cols-2">
            <Section title="Why leads were lost" onCsv={() => csv('lost-reasons', ['Reason', 'Leads', 'Budget lost'], d.lost_reasons.map((r: any) => [r.reason, r.n, r.budget]))}>
              {d.lost_reasons.length === 0 ? (
                <p className="px-4 pb-4 text-sm text-muted">No lost leads in this period.</p>
              ) : (
                <ul className="space-y-3 px-4 pb-4">
                  {d.lost_reasons.map((r: any) => {
                    const max = Math.max(...d.lost_reasons.map((x: any) => Number(x.n)));
                    return (
                      <li key={r.reason}>
                        <div className="flex justify-between text-sm"><span>{r.reason}</span><span className="tabular text-muted">{r.n} · {inrShort(r.budget)}</span></div>
                        <div className="mt-1 h-2 rounded-full bg-subtle"><div className="h-2 rounded-full" style={{ width: `${(Number(r.n) / max) * 100}%`, background: series(7, dark) }} /></div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </Section>
            <Section title="Year on year" subtitle="All time, by calendar year" onCsv={() => csv('yearly', ['Year', 'Leads', 'Booked', 'Bookings', 'Bookings value', 'Collected', 'Growth %'], d.yearly.map((y: any) => [y.year, y.leads, y.booked, y.bookings, y.booked_value, y.collected, y.growth]))}>
              <Table head={['Year', 'Leads', 'Bookings', 'Value', 'Collected', 'Growth']}>
                {d.yearly.map((y: any) => (
                  <tr key={y.year}>
                    <td className="td font-medium">{y.year}</td>
                    <td className="td tabular text-right">{y.leads}</td>
                    <td className="td tabular text-right">{y.bookings}</td>
                    <td className="td tabular text-right">{inrShort(y.booked_value)}</td>
                    <td className="td tabular text-right">{inrShort(y.collected)}</td>
                    <td className="td tabular text-right">{y.growth === null ? '—' : <span className={y.growth >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600'}>{y.growth > 0 ? '+' : ''}{y.growth}%</span>}</td>
                  </tr>
                ))}
              </Table>
            </Section>
          </div>

          <Section title="Pending payments" subtitle={`${inr(s.pending)} outstanding across all active bookings (not limited to the date range)`} onCsv={() => csv('pending-payments', ['Booking', 'Client', 'Package', 'Received', 'Pending', 'Status', 'Next due', 'Next due date'], d.pending.map((p: any) => [p.number, p.client_name, p.total, p.paid, p.pending, payLabel[p.status], p.next_due?.amount, p.next_due?.due_date ? fmtDate(p.next_due.due_date) : '']))}>
            <Table head={['Booking', 'Client', 'Package', 'Received', 'Pending', 'Status', 'Next due']}>
              {d.pending.map((p: any) => (
                <tr key={p.id}>
                  <td className="td"><Link to={`/bookings/${p.id}`} className="font-medium hover:text-accent-ink">{p.number}</Link></td>
                  <td className="td">{p.client_name}</td>
                  <td className="td tabular text-right">{inr(p.total)}</td>
                  <td className="td tabular text-right">{inr(p.paid)}</td>
                  <td className="td tabular text-right font-medium">{inr(p.pending)}</td>
                  <td className="td text-right"><Badge tone={payTone[p.status]}>{payLabel[p.status]}</Badge></td>
                  <td className="td whitespace-nowrap text-right text-sm text-muted">{p.next_due?.due_date ? fmtDate(p.next_due.due_date) : '—'}</td>
                </tr>
              ))}
            </Table>
          </Section>
        </div>
      )}
    </div>
  );
}

function Tile({ label, value, sub }: { label: string; value: ReactNode; sub?: string }) {
  return (
    <div className="card p-3.5">
      <p className="truncate text-xs text-muted">{label}</p>
      <p className="tabular mt-0.5 text-lg font-semibold">{value}</p>
      {sub && <p className="truncate text-2xs text-faint">{sub}</p>}
    </div>
  );
}

function Section({ title, subtitle, onCsv, children }: { title: string; subtitle?: string; onCsv?: () => void; children: ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} subtitle={subtitle} actions={onCsv && <Button size="xs" variant="ghost" icon={<Download className="h-3.5 w-3.5" />} onClick={onCsv}>CSV</Button>} />
      {children}
    </Card>
  );
}

function Table({ head, children }: { head: string[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto pb-2">
      <table className="w-full">
        <thead className="border-y border-line bg-subtle/50"><tr>{head.map((h, i) => <th key={h} className={i ? 'th text-right' : 'th'}>{h}</th>)}</tr></thead>
        <tbody className="divide-y divide-line">{children}</tbody>
      </table>
    </div>
  );
}
