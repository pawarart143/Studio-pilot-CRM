import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Plus, UserRound } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { fmtDate, inr, phoneDisplay, telLink } from '@/lib/format';
import { Avatar, Button, Card, EmptyState, ErrorState, PageHeader, Pagination, SearchInput, TableSkeleton } from '@/components/ui';
import { ClientFormModal } from './ClientForm';

export default function ClientsList() {
  const { can } = useAuth();
  const nav = useNavigate();
  const [f, setF] = useUrlState({ q: '', page: '1' });
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq, page: '1' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const [open, setOpen] = useState(false);
  const params = { q: f.q, page: f.page, per_page: 25 };
  const q = useQuery({ queryKey: ['clients', params], queryFn: () => api.get<Paged<any>>('clients', params), placeholderData: keepPreviousData });
  const money = can('payments.view');
  const items = q.data?.items || [];
  return (
    <div>
      <PageHeader
        title="Clients"
        subtitle="Everyone who has booked with you, with their enquiries, bookings and payments in one place."
        actions={can('clients.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setOpen(true)}>New client</Button>}
      />
      <Card>
        <div className="border-b border-line p-3"><SearchInput value={search} onChange={setSearch} placeholder="Name, phone, email, city…" className="w-full sm:w-72" /></div>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : items.length === 0 ? (
          <EmptyState icon={<UserRound className="h-5 w-5" />} title={f.q ? 'No clients match' : 'No clients yet'} description={f.q ? undefined : 'A client profile is created automatically when a lead is booked.'} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Client</th><th className="th">Phone</th><th className="th">City</th><th className="th text-center">Bookings</th>{money && <th className="th text-right">Business</th>}{money && <th className="th text-right">Pending</th>}<th className="th">Since</th></tr></thead>
                <tbody className="divide-y divide-line">
                  {items.map((c) => (
                    <tr key={c.id} className="cursor-pointer hover:bg-subtle/50" onClick={() => nav(`/clients/${c.id}`)}>
                      <td className="td"><span className="flex items-center gap-2.5"><Avatar name={c.name} size="sm" /><span><Link to={`/clients/${c.id}`} className="font-medium hover:text-accent-ink" onClick={(e) => e.stopPropagation()}>{c.name}</Link>{c.email && <span className="block text-xs text-muted">{c.email}</span>}</span></span></td>
                      <td className="td whitespace-nowrap text-sm"><a href={telLink(c.phone)} onClick={(e) => e.stopPropagation()} className="hover:text-accent-ink">{phoneDisplay(c.phone)}</a></td>
                      <td className="td text-sm text-muted">{c.city || '—'}</td>
                      <td className="td tabular text-center">{c.bookings_count}</td>
                      {money && <td className="td tabular whitespace-nowrap text-right">{Number(c.total_amount) ? inr(c.total_amount) : '—'}</td>}
                      {money && <td className="td tabular whitespace-nowrap text-right">{Number(c.total_amount) - Number(c.paid) > 0 ? inr(Number(c.total_amount) - Number(c.paid)) : '—'}</td>}
                      <td className="td whitespace-nowrap text-sm text-muted">{fmtDate(c.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={Number(f.page)} perPage={25} total={q.data?.total || 0} onPage={(p) => setF({ page: String(p) })} />
          </>
        )}
      </Card>
      <ClientFormModal open={open} onClose={() => setOpen(false)} onSaved={(c) => nav(`/clients/${c.id}`)} />
    </div>
  );
}
