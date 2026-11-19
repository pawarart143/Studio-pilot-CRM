import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { CalendarCheck, Plus } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { fmtDate, fmtDateShort, inr, phoneDisplay, dayjs } from '@/lib/format';
import { bookingTone, payLabel, payTone } from '@/lib/tones';
import { Badge, Button, Card, EmptyState, ErrorState, Input, PageHeader, Pagination, SearchInput, Segmented, Select, TableSkeleton } from '@/components/ui';

const defaults = { q: '', status: '', when: 'upcoming', event_type: '', from: '', to: '', page: '1' };

export default function BookingsList() {
  const { config, can } = useAuth();
  const nav = useNavigate();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq, page: '1' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const money = can('payments.view');
  const range = f.when === 'upcoming' ? { from: dayjs().format('YYYY-MM-DD'), to: '' } : f.when === 'past' ? { from: '', to: dayjs().subtract(1, 'day').format('YYYY-MM-DD') } : { from: f.from, to: f.to };
  const params = { q: f.q, status: f.status, event_type: f.event_type, ...range, page: f.page, per_page: 25 };
  const q = useQuery({ queryKey: ['bookings', params], queryFn: () => api.get<Paged<any>>('bookings', params), placeholderData: keepPreviousData });
  const items = q.data?.items || [];
  const filtered = !!(f.q || f.status || f.event_type || f.when !== 'upcoming');

  const dates = (r: any) => (!r.first_date ? '—' : r.first_date === r.last_date ? fmtDate(r.first_date) : `${fmtDateShort(r.first_date)} – ${fmtDate(r.last_date)}`);

  return (
    <div>
      <PageHeader
        title="Bookings"
        subtitle="Confirmed projects with their events, team and payments."
        actions={can('bookings.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => nav('/bookings/new')}>New booking</Button>}
      />
      <Card>
        <div className="flex flex-col gap-2 border-b border-line p-3 lg:flex-row lg:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Booking no., client, phone…" className="w-full lg:w-64" />
          <Segmented value={f.when} onChange={(v) => setF({ when: v, page: '1' })} options={[['upcoming', 'Upcoming'], ['past', 'Past'], ['all', 'All'], ['range', 'Dates']]} />
          {f.when === 'range' && (
            <div className="flex items-center gap-2">
              <Input type="date" value={f.from} onChange={(e) => setF({ from: e.target.value, page: '1' })} className="w-auto py-1.5" aria-label="From" />
              <span className="text-xs text-muted">to</span>
              <Input type="date" value={f.to} onChange={(e) => setF({ to: e.target.value, page: '1' })} className="w-auto py-1.5" aria-label="To" />
            </div>
          )}
          <div className="flex gap-2 lg:ml-auto">
            <Select value={f.status} onChange={(e) => setF({ status: e.target.value, page: '1' })} options={config.booking_statuses} placeholder="All statuses" className="w-auto py-1.5" />
            <Select value={f.event_type} onChange={(e) => setF({ event_type: e.target.value, page: '1' })} options={config.event_types} placeholder="All events" className="w-auto py-1.5" />
          </div>
        </div>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : items.length === 0 ? (
          <EmptyState
            icon={<CalendarCheck className="h-5 w-5" />}
            title={filtered ? 'No bookings match' : 'No upcoming bookings'}
            description={filtered ? 'Try other filters.' : 'Convert a lead or an accepted quotation into a booking.'}
            action={!filtered && can('bookings.edit') && <Button variant="primary" onClick={() => nav('/bookings/new')}>New booking</Button>}
          />
        ) : (
          <>
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full">
                <thead className="border-b border-line bg-subtle/50">
                  <tr>
                    <th className="th">Booking</th>
                    <th className="th">Client</th>
                    <th className="th">Event dates</th>
                    {money && <th className="th text-right">Package</th>}
                    {money && <th className="th text-right">Pending</th>}
                    <th className="th">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {items.map((r) => (
                    <tr key={r.id} className="cursor-pointer hover:bg-subtle/50" onClick={() => nav(`/bookings/${r.id}`)}>
                      <td className="td">
                        <Link to={`/bookings/${r.id}`} className="font-medium hover:text-accent-ink" onClick={(e) => e.stopPropagation()}>{r.title}</Link>
                        <p className="text-xs text-muted">{r.number}{r.event_type ? ' · ' + config.event_types[r.event_type] : ''}</p>
                      </td>
                      <td className="td"><p className="text-sm">{r.client_name}</p><p className="text-xs text-muted">{phoneDisplay(r.client_phone)}</p></td>
                      <td className="td whitespace-nowrap text-sm">{dates(r)}<p className="text-xs text-muted">{r.events_count} event{r.events_count === 1 ? '' : 's'}</p></td>
                      {money && <td className="td tabular whitespace-nowrap text-right">{inr(r.total_amount)}</td>}
                      {money && (
                        <td className="td whitespace-nowrap text-right">
                          <span className="tabular">{r.pending > 0 ? inr(r.pending) : '—'}</span>
                          <p><Badge tone={payTone[r.payment_status]}>{payLabel[r.payment_status]}</Badge></p>
                        </td>
                      )}
                      <td className="td whitespace-nowrap"><Badge tone={bookingTone[r.status]}>{config.booking_statuses[r.status]}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="divide-y divide-line md:hidden">
              {items.map((r) => (
                <li key={r.id}>
                  <Link to={`/bookings/${r.id}`} className="block px-4 py-3">
                    <div className="flex items-start justify-between gap-2">
                      <span className="font-medium">{r.title}</span>
                      <Badge tone={bookingTone[r.status]}>{config.booking_statuses[r.status]}</Badge>
                    </div>
                    <p className="mt-1 text-xs text-muted">{dates(r)} · {r.events_count} event{r.events_count === 1 ? '' : 's'}</p>
                    {money && (
                      <p className="mt-1 flex items-center gap-2 text-xs">
                        <span className="tabular">{inr(r.total_amount)}</span>
                        <Badge tone={payTone[r.payment_status]}>{payLabel[r.payment_status]}</Badge>
                        {r.pending > 0 && <span className="tabular text-muted">{inr(r.pending)} due</span>}
                      </p>
                    )}
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination page={Number(f.page)} perPage={25} total={q.data?.total || 0} onPage={(p) => setF({ page: String(p) })} />
          </>
        )}
      </Card>
    </div>
  );
}
