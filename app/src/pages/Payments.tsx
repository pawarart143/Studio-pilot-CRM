import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlertCircle, History, IndianRupee, Pencil, Plus, Receipt, ShieldCheck, Trash2, TrendingDown, Wallet } from 'lucide-react';
import { api, type Paged } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { fmtDate, inr, inrShort, dayjs } from '@/lib/format';
import { payLabel, payTone, paymentStatusTone, paymentTypeTone } from '@/lib/tones';
import { Badge, Button, Card, EmptyState, ErrorState, Input, PageHeader, Pagination, SearchInput, Select, StatCard, TableSkeleton, Tabs, useUi } from '@/components/ui';
import { WaButton } from '@/components/shared';
import { ExpenseModal, PaymentModal } from '@/components/forms';
import { LogList, PaymentHistoryModal } from '@/components/delivery';

const defaults = { tab: 'dues', status: '', q: '', from: '', to: '', method: '', category: '', pstatus: '', ptype: '', action: '', page: '1' };

export default function Payments() {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [f, setF] = useUrlState(defaults);
  const [search, setSearch] = useState(f.q);
  const dq = useDebounce(search, 300);
  useEffect(() => {
    if (dq !== f.q) setF({ q: dq, page: '1' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const [payFor, setPayFor] = useState<any>(null);
  const [editPay, setEditPay] = useState<any>(null);
  const [addPay, setAddPay] = useState(false);
  const [history, setHistory] = useState<number | null>(null);
  const [expense, setExpense] = useState<any>(null);
  const admin = can('payments.edit');

  const summary = useQuery({ queryKey: ['payments-summary'], queryFn: () => api.get('payments/summary') });
  const dues = useQuery({ queryKey: ['payments-dues', f.status, f.q], queryFn: () => api.get<any[]>('payments/dues', { status: f.status, q: f.q }), enabled: f.tab === 'dues' });
  const txParams = { q: f.q, from: f.from, to: f.to, method: f.method, status: f.pstatus, payment_type: f.ptype, page: f.page, per_page: 30 };
  const actParams = { action: f.action, page: f.page, per_page: 30 };
  const act = useQuery({ queryKey: ['payment-activity', actParams], queryFn: () => api.get<Paged<any>>('payments/activity', actParams), enabled: f.tab === 'activity', placeholderData: keepPreviousData });
  const tx = useQuery({ queryKey: ['payments', txParams], queryFn: () => api.get<Paged<any>>('payments', txParams), enabled: f.tab === 'transactions', placeholderData: keepPreviousData });
  const exParams = { from: f.from, to: f.to, category: f.category, page: f.page, per_page: 30 };
  const ex = useQuery({ queryKey: ['expenses', exParams], queryFn: () => api.get<Paged<any>>('expenses', exParams), enabled: f.tab === 'expenses' && can('expenses.view'), placeholderData: keepPreviousData });
  const s = summary.data;

  const switchTab = (tab: string) => setF({ tab, page: '1', status: '', method: '', category: '', pstatus: '', ptype: '', action: '' });
  const receipt = (p: any) => api.openPdf(`payments/${p.id}/receipt`, `${p.receipt_no}.pdf`).catch((e) => toast(e.message, 'error'));
  const delPayment = async (p: any) => {
    if (!(await confirm({ title: `Delete payment ${p.receipt_no}?`, message: `${inr(p.amount)} from ${p.client_name} will be removed and the receipt voided. The deletion is kept in the payment activity log.`, danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`payments/${p.id}`);
      ['payments', 'payments-dues', 'payments-summary', 'booking', 'dashboard', 'payment-activity', 'reports'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      toast('Payment deleted');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const delExpense = async (x: any) => {
    if (!(await confirm({ title: 'Delete this expense?', message: `${inr(x.amount)} · ${config.expense_categories[x.category]}`, danger: true, confirmLabel: 'Delete' }))) return;
    try {
      await api.del(`expenses/${x.id}`);
      ['expenses', 'payments-summary', 'booking', 'reports'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      toast('Expense deleted');
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const DateRange = (
    <div className="flex items-center gap-2">
      <Input type="date" value={f.from} onChange={(e) => setF({ from: e.target.value, page: '1' })} className="w-auto py-1.5" aria-label="From date" />
      <span className="text-xs text-muted">to</span>
      <Input type="date" value={f.to} onChange={(e) => setF({ to: e.target.value, page: '1' })} className="w-auto py-1.5" aria-label="To date" />
      <Button size="sm" variant="ghost" onClick={() => setF({ from: dayjs().startOf('month').format('YYYY-MM-DD'), to: dayjs().format('YYYY-MM-DD'), page: '1' })}>This month</Button>
      {(f.from || f.to) && <Button size="sm" variant="ghost" onClick={() => setF({ from: '', to: '', page: '1' })}>Clear</Button>}
    </div>
  );

  return (
    <div>
      <PageHeader
        title="Payments"
        subtitle={admin ? 'Dues tracker, payment records with receipts, audit log and studio expenses.' : 'View-only: only the studio owner or an admin can add or change payments.'}
        actions={
          f.tab === 'expenses' ? (
            can('expenses.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setExpense({})}>Add expense</Button>
          ) : (
            admin && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setAddPay(true)}>Add payment</Button>
          )
        }
      />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Collected this month" value={s ? inrShort(s.collected_month) : '…'} icon={<IndianRupee className="h-4 w-4" />} tone="green" onClick={() => setF({ tab: 'transactions', from: dayjs().startOf('month').format('YYYY-MM-DD'), to: '', page: '1' })} />
        <StatCard label="Pending (all bookings)" value={s ? inrShort(s.pending) : '…'} sub={s ? `${s.counts.pending + s.counts.partial + s.counts.overdue} bookings` : ''} icon={<Wallet className="h-4 w-4" />} tone="amber" onClick={() => setF({ tab: 'dues', status: '', page: '1' })} />
        <StatCard label="Overdue" value={s ? inrShort(s.overdue) : '…'} sub={s ? `${s.overdue_count} bookings` : ''} icon={<AlertCircle className="h-4 w-4" />} tone="red" onClick={() => setF({ tab: 'dues', status: 'overdue', page: '1' })} />
        <StatCard label="Expenses this month" value={s ? inrShort(s.expenses_month) : '…'} icon={<TrendingDown className="h-4 w-4" />} tone="violet" onClick={can('expenses.view') ? () => setF({ tab: 'expenses', from: dayjs().startOf('month').format('YYYY-MM-DD'), to: '', page: '1' }) : undefined} />
      </div>

      <Card>
        <div className="px-4 pt-1">
          <Tabs value={f.tab} onChange={switchTab} tabs={[['dues', 'Dues tracker'], ['transactions', 'Payment records'], ['activity', 'Activity log'], ...(can('expenses.view') ? ([['expenses', 'Expenses']] as [string, string][]) : [])]} />
        </div>

        {f.tab === 'dues' && (
          <>
            <div className="flex flex-col gap-2 border-b border-line p-3 sm:flex-row sm:items-center">
              <SearchInput value={search} onChange={setSearch} placeholder="Client, booking…" className="w-full sm:w-64" />
              <Select value={f.status} onChange={(e) => setF({ status: e.target.value })} options={[['', 'Unpaid (all)'], ['overdue', 'Overdue'], ['partial', 'Partially Paid'], ['pending', 'Pending'], ['paid', 'Paid'], ['all', 'All bookings']]} className="w-auto py-1.5" />
            </div>
            {dues.error ? (
              <ErrorState error={dues.error} onRetry={() => dues.refetch()} />
            ) : dues.isLoading ? (
              <TableSkeleton />
            ) : !dues.data?.length ? (
              <EmptyState icon={<Wallet className="h-5 w-5" />} title="No dues here" description="Every booking in this view is settled." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Booking</th><th className="th">Next due</th><th className="th text-right">Package</th><th className="th text-right">Received</th><th className="th text-right">Pending</th><th className="th">Status</th><th className="th" /></tr></thead>
                  <tbody className="divide-y divide-line">
                    {dues.data.map((b) => {
                      const od = b.next_due?.due_date && b.next_due.due_date < dayjs().format('YYYY-MM-DD');
                      return (
                        <tr key={b.id}>
                          <td className="td"><Link to={`/bookings/${b.id}`} className="font-medium hover:text-accent-ink">{b.client_name}</Link><p className="text-xs text-muted">{b.number} · {b.first_date ? 'Event ' + fmtDate(b.first_date) : b.title}</p></td>
                          <td className={clsx('td whitespace-nowrap text-sm', od && 'font-medium text-red-600')}>{b.next_due ? <>{fmtDate(b.next_due.due_date)}<p className="text-xs font-normal text-muted">{b.next_due.label} · {inr(b.next_due.amount)}</p></> : '—'}</td>
                          <td className="td tabular whitespace-nowrap text-right">{inr(b.total_amount)}</td>
                          <td className="td tabular whitespace-nowrap text-right text-muted">{inr(b.paid)}</td>
                          <td className="td tabular whitespace-nowrap text-right font-medium">{inr(b.pending)}</td>
                          <td className="td"><Badge tone={payTone[b.payment_status]}>{payLabel[b.payment_status]}</Badge></td>
                          <td className="td whitespace-nowrap text-right">
                            <div className="flex justify-end gap-1.5">
                              {b.pending > 0 && <WaButton ctx={{ booking_id: b.id }} template="payment_reminder" label="Remind" size="xs" />}
                              {b.pending > 0 && can('payments.edit') && <Button size="xs" variant="primary" onClick={() => setPayFor({ id: b.id, number: b.number, title: b.title, payment: { pending: b.pending, installments: b.installments, total: b.total_amount } })}>Record</Button>}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

        {f.tab === 'transactions' && (
          <>
            <div className="flex flex-col gap-2 border-b border-line p-3 lg:flex-row lg:items-center">
              <SearchInput value={search} onChange={setSearch} placeholder="Receipt, client, ref…" className="w-full lg:w-56" />
              {DateRange}
              <div className="flex flex-wrap gap-2 lg:ml-auto">
                <Select value={f.method} onChange={(e) => setF({ method: e.target.value, page: '1' })} options={config.payment_methods} placeholder="All methods" className="w-auto py-1.5" />
                <Select value={f.ptype} onChange={(e) => setF({ ptype: e.target.value, page: '1' })} options={config.payment_types} placeholder="All types" className="w-auto py-1.5" />
                <Select value={f.pstatus} onChange={(e) => setF({ pstatus: e.target.value, page: '1' })} options={config.payment_statuses} placeholder="All statuses" className="w-auto py-1.5" />
              </div>
            </div>
            {tx.error ? (
              <ErrorState error={tx.error} onRetry={() => tx.refetch()} />
            ) : tx.isLoading ? (
              <TableSkeleton />
            ) : !tx.data?.items.length ? (
              <EmptyState icon={<Receipt className="h-5 w-5" />} title="No payments found" />
            ) : (
              <>
                <p className="border-b border-line px-4 py-2 text-xs text-muted">{tx.data.total} payments · <b className="tabular text-ink">{inr(tx.data.sum)}</b> received</p>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[980px]">
                    <thead className="border-b border-line bg-subtle/50">
                      <tr><th className="th">Date</th><th className="th">Client · Booking</th><th className="th">Type</th><th className="th">Method · Transaction ID</th><th className="th">Status</th><th className="th text-right">Package</th><th className="th text-right">Amount</th><th className="th">Created / modified by</th><th className="th" /></tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {tx.data.items.map((p) => (
                        <tr key={p.id} className={clsx(p.status !== 'received' && 'bg-subtle/30')}>
                          <td className="td whitespace-nowrap text-sm">{fmtDate(p.paid_on)}<p className="text-2xs text-faint">{p.receipt_no}</p></td>
                          <td className="td"><Link to={`/bookings/${p.booking_id}`} className="text-sm font-medium hover:text-accent-ink">{p.client_name}</Link><p className="text-xs text-muted">{p.booking_number}{p.installment_label ? ' · ' + p.installment_label : ''}</p></td>
                          <td className="td"><Badge tone={paymentTypeTone[p.payment_type]}>{config.payment_types[p.payment_type] || p.payment_type}</Badge></td>
                          <td className="td text-sm">{config.payment_methods[p.method]}<p className="max-w-[180px] truncate text-xs text-muted" title={p.transaction_ref || ''}>{p.transaction_ref || '—'}</p></td>
                          <td className="td"><Badge tone={paymentStatusTone[p.status]} dot>{config.payment_statuses[p.status] || p.status}</Badge></td>
                          <td className="td tabular whitespace-nowrap text-right text-sm text-muted">{inr(p.package_amount)}</td>
                          <td className={clsx('td tabular whitespace-nowrap text-right font-medium', p.status !== 'received' && 'text-muted line-through decoration-1')}>{inr(p.amount)}</td>
                          <td className="td text-xs"><span className="block">{p.created_by_name || 'System'}</span>{p.updated_by_name && <span className="text-muted">Edited by {p.updated_by_name}{p.updated_at ? ' · ' + fmtDate(p.updated_at) : ''}</span>}</td>
                          <td className="td whitespace-nowrap text-right">
                            <div className="flex justify-end gap-0.5">
                              {p.status === 'received' && <button onClick={() => receipt(p)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Receipt" title="Receipt PDF"><Receipt className="h-4 w-4" /></button>}
                              <button onClick={() => setHistory(p.id)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="History" title="Change history"><History className="h-4 w-4" /></button>
                              {admin && <button onClick={() => setEditPay(p)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Edit payment" title="Edit"><Pencil className="h-4 w-4" /></button>}
                              {admin && <button onClick={() => delPayment(p)} className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label="Delete payment" title="Delete"><Trash2 className="h-4 w-4" /></button>}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <Pagination page={Number(f.page)} perPage={30} total={tx.data.total} onPage={(p) => setF({ page: String(p) })} />
              </>
            )}
          </>
        )}

        {f.tab === 'activity' && (
          <>
            <div className="flex flex-col gap-2 border-b border-line p-3 sm:flex-row sm:items-center">
              <p className="flex items-center gap-1.5 text-xs text-muted"><ShieldCheck className="h-4 w-4 text-emerald-600" />Every payment created, edited or deleted, with who did it and when. Entries cannot be changed.</p>
              <Select value={f.action} onChange={(e) => setF({ action: e.target.value, page: '1' })} options={[['', 'All actions'], ['created', 'Created'], ['updated', 'Edited'], ['deleted', 'Deleted']]} className="w-auto py-1.5 sm:ml-auto" />
            </div>
            {act.error ? (
              <ErrorState error={act.error} onRetry={() => act.refetch()} />
            ) : act.isLoading ? (
              <TableSkeleton />
            ) : !act.data?.items.length ? (
              <EmptyState icon={<History className="h-5 w-5" />} title="No payment activity yet" description="Changes to payments made from v0.4.0 on are listed here." />
            ) : (
              <>
                <div className="p-4"><LogList items={act.data.items} showBooking /></div>
                <Pagination page={Number(f.page)} perPage={30} total={act.data.total} onPage={(p) => setF({ page: String(p) })} />
              </>
            )}
          </>
        )}

        {f.tab === 'expenses' && (
          <>
            <div className="flex flex-col gap-2 border-b border-line p-3 lg:flex-row lg:items-center">
              {DateRange}
              <Select value={f.category} onChange={(e) => setF({ category: e.target.value, page: '1' })} options={config.expense_categories} placeholder="All categories" className="w-auto py-1.5 lg:ml-auto" />
            </div>
            {ex.error ? (
              <ErrorState error={ex.error} onRetry={() => ex.refetch()} />
            ) : ex.isLoading ? (
              <TableSkeleton />
            ) : !ex.data?.items.length ? (
              <EmptyState icon={<TrendingDown className="h-5 w-5" />} title="No expenses recorded" action={can('expenses.edit') && <Button variant="primary" onClick={() => setExpense({})}>Add expense</Button>} />
            ) : (
              <div className="grid lg:grid-cols-[1fr_280px]">
                <div className="min-w-0 lg:border-r lg:border-line">
                  <ul className="divide-y divide-line">
                    {ex.data.items.map((x) => (
                      <li key={x.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                        <span className="w-24 shrink-0 text-xs text-muted">{fmtDate(x.spent_on)}</span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate">{x.description || config.expense_categories[x.category]}</p>
                          <p className="truncate text-xs text-muted">{config.expense_categories[x.category]}{x.booking_id ? <> · <Link to={`/bookings/${x.booking_id}`} className="hover:text-accent-ink">{x.booking_number}</Link></> : ' · General'}</p>
                        </div>
                        <span className="tabular font-medium">{inr(x.amount)}</span>
                        {can('expenses.edit') && (
                          <>
                            <button onClick={() => setExpense(x)} className="rounded p-1 text-faint hover:text-ink" aria-label="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                            <button onClick={() => delExpense(x)} className="rounded p-1 text-faint hover:text-red-600" aria-label="Delete"><Trash2 className="h-3.5 w-3.5" /></button>
                          </>
                        )}
                      </li>
                    ))}
                  </ul>
                  <Pagination page={Number(f.page)} perPage={30} total={ex.data.total} onPage={(p) => setF({ page: String(p) })} />
                </div>
                <div className="border-t border-line p-4 lg:border-t-0">
                  <p className="text-xs font-medium text-muted">Total in view</p>
                  <p className="tabular mb-4 text-xl font-semibold">{inr(ex.data.sum)}</p>
                  <p className="mb-2 text-xs font-medium text-muted">By category</p>
                  <ul className="space-y-2.5">
                    {ex.data.by_category.map((c: any) => (
                      <li key={c.category}>
                        <div className="flex justify-between text-xs"><span>{config.expense_categories[c.category]}</span><span className="tabular text-muted">{inr(c.amount)}</span></div>
                        <div className="mt-1 h-1.5 rounded-full bg-subtle"><div className="h-1.5 rounded-full bg-accent" style={{ width: `${(Number(c.amount) / Math.max(1, ex.data!.sum)) * 100}%` }} /></div>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            )}
          </>
        )}
      </Card>
      <PaymentModal open={!!payFor} onClose={() => setPayFor(null)} booking={payFor} />
      <PaymentModal open={addPay} onClose={() => setAddPay(false)} booking={null} pickBooking />
      <PaymentModal open={!!editPay} onClose={() => setEditPay(null)} booking={editPay ? { id: editPay.booking_id, number: editPay.booking_number, title: editPay.booking_title, payment: null } : null} payment={editPay} />
      <PaymentHistoryModal paymentId={history} onClose={() => setHistory(null)} />
      <ExpenseModal open={!!expense} onClose={() => setExpense(null)} expense={expense?.id ? expense : undefined} />
    </div>
  );
}
