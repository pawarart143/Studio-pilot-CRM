import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { FileText, Plus } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { fmtDate, inr, inrShort, phoneDisplay } from '@/lib/format';
import { quoteTone } from '@/lib/tones';
import { Badge, Button, Card, EmptyState, ErrorState, PageHeader, Pagination, SearchInput, StatCard, TableSkeleton, Tabs } from '@/components/ui';

const defaults = { q: '', status: '', page: '1' };

export default function QuotationsList() {
  const { config, can } = useAuth();
  const nav = useNavigate();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const params = { ...f, per_page: 25 };
  const q = useQuery({ queryKey: ['quotations', params], queryFn: () => api.get<Paged<any>>('quotations', params), placeholderData: keepPreviousData });
  const stats: Record<string, { n: string; amount: string }> = q.data?.stats || {};
  const stat = (k: string) => Number(stats[k]?.n || 0);
  const amt = (k: string) => Number(stats[k]?.amount || 0);
  const open = ['sent', 'viewed'].reduce((s, k) => s + amt(k), 0);
  const total = Object.keys(stats).reduce((s, k) => s + stat(k), 0);
  const decided = stat('accepted') + stat('rejected') + stat('expired');
  const items = q.data?.items || [];

  return (
    <div>
      <PageHeader
        title="Quotations"
        subtitle="Build quotes with your service catalogue, share them on WhatsApp and track who has viewed them."
        actions={can('quotes.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => nav('/quotations/new')}>New quotation</Button>}
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Total quotations" value={total} icon={<FileText className="h-4 w-4" />} tone="indigo" />
        <StatCard label="Awaiting reply" value={inrShort(open)} sub={`${stat('sent') + stat('viewed')} sent or viewed`} tone="blue" onClick={() => setF({ status: 'sent', page: '1' })} />
        <StatCard label="Accepted value" value={inrShort(amt('accepted'))} sub={`${stat('accepted')} accepted`} tone="green" onClick={() => setF({ status: 'accepted', page: '1' })} />
        <StatCard label="Win rate" value={decided ? Math.round((stat('accepted') / decided) * 100) + '%' : '—'} sub="Accepted of decided" tone="violet" />
      </div>
      <Card>
        <div className="flex flex-col gap-3 px-4 pt-3 sm:flex-row sm:items-center">
          <Tabs
            className="flex-1"
            value={f.status}
            onChange={(v) => setF({ status: v, page: '1' })}
            tabs={[['', 'All', total] as [string, string, number], ...Object.entries(config.quotation_statuses).map(([k, v]) => [k, v, stat(k)] as [string, string, number])]}
          />
          <SearchInput value={search} onChange={setSearch} placeholder="Number, client, phone…" className="w-full pb-2 sm:w-64" />
        </div>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : items.length === 0 ? (
          <EmptyState
            icon={<FileText className="h-5 w-5" />}
            title={f.q || f.status ? 'No quotations match' : 'No quotations yet'}
            description={f.q || f.status ? 'Try a different search or status.' : 'Create your first quotation from a lead or from scratch.'}
            action={can('quotes.edit') && !f.q && !f.status && <Button variant="primary" onClick={() => nav('/quotations/new')}>New quotation</Button>}
          />
        ) : (
          <>
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full">
                <thead className="border-b border-line bg-subtle/50">
                  <tr><th className="th">Number</th><th className="th">Client</th><th className="th">Event</th><th className="th text-right">Amount</th><th className="th">Valid until</th><th className="th">Status</th></tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {items.map((r) => (
                    <tr key={r.id} className="cursor-pointer hover:bg-subtle/50" onClick={() => nav(`/quotations/${r.id}`)}>
                      <td className="td whitespace-nowrap"><Link to={`/quotations/${r.id}`} className="font-medium hover:text-accent-ink" onClick={(e) => e.stopPropagation()}>{r.number}</Link><p className="max-w-[14rem] truncate text-xs text-muted">{r.title}</p></td>
                      <td className="td"><p className="font-medium">{r.customer_name}</p><p className="text-xs text-muted">{phoneDisplay(r.customer_phone)}</p></td>
                      <td className="td whitespace-nowrap text-sm text-muted">{r.event_type ? config.event_types[r.event_type] : '—'}<p className="text-xs">{fmtDate(r.event_date)}</p></td>
                      <td className="td tabular whitespace-nowrap text-right font-medium">{inr(r.total)}</td>
                      <td className="td whitespace-nowrap text-sm text-muted">{fmtDate(r.valid_until)}</td>
                      <td className="td whitespace-nowrap">
                        <Badge tone={quoteTone[r.status]}>{config.quotation_statuses[r.status]}</Badge>
                        {r.booking_id ? <Badge tone="green" className="ml-1.5">Booked</Badge> : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="divide-y divide-line md:hidden">
              {items.map((r) => (
                <li key={r.id}>
                  <Link to={`/quotations/${r.id}`} className="block px-4 py-3">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium">{r.customer_name}</span>
                      <span className="tabular font-medium">{inr(r.total)}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-muted">
                      <span>{r.number}</span>·<span>{fmtDate(r.event_date)}</span>
                      <Badge tone={quoteTone[r.status]} className="ml-auto">{config.quotation_statuses[r.status]}</Badge>
                    </div>
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
