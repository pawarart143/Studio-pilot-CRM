import { Fragment, useEffect, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, IndianRupee, MoreHorizontal, Pencil, Plus, Trash2, Wallet } from 'lucide-react';
import { api, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useForm, validate, clean } from '@/lib/form';
import { useDebounce, useUrlState } from '@/lib/hooks';
import { dayjs, fmtDate, inr, today } from '@/lib/format';
import { Badge, Button, Card, EmptyState, ErrorState, Field, IconButton, Input, Menu, MenuItem, Modal, SearchInput, Select, StatCard, TableSkeleton, Textarea, useUi } from '@/components/ui';
import { crewPayTone, useCrew } from './shared';

export default function CrewPayments({ crewMemberId }: { crewMemberId?: number }) {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [f, setF] = useUrlState({ pq: '', pstatus: '', pfrom: '', pto: '' });
  const dq = useDebounce(f.pq, 300);
  const params = { q: dq, status: f.pstatus, from: f.pfrom, to: f.pto, crew_member_id: crewMemberId || '' };
  const q = useQuery({ queryKey: ['crew-payments', params], queryFn: () => api.get('crew-payments', params), placeholderData: keepPreviousData });
  const [open, setOpen] = useState<Record<number, boolean>>({});
  const [pay, setPay] = useState<any>(null);
  const [edit, setEdit] = useState<any>(null);
  const rows: any[] = q.data?.items || [];
  const sum = q.data?.summary || { agreed: 0, paid: 0, remaining: 0 };
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['crew-payments'] });
    qc.invalidateQueries({ queryKey: ['crew'] });
    qc.invalidateQueries({ queryKey: ['reports'] });
  };

  const delEntry = async (e: any) => {
    if (!(await confirm({ title: 'Delete this payment?', message: `${inr(e.amount)} paid on ${fmtDate(e.paid_on)} is removed from the record.`, confirmLabel: 'Delete', danger: true }))) return;
    try {
      await api.del(`crew-payments/entries/${e.id}`);
      refresh();
      toast('Payment deleted');
    } catch (err) {
      toast(errMsg(err), 'error');
    }
  };
  const delRecord = async (r: any) => {
    if (!(await confirm({ title: 'Delete this payment record?', confirmLabel: 'Delete', danger: true }))) return;
    try {
      await api.del(`crew-payments/${r.id}`);
      refresh();
      toast('Record deleted');
    } catch (err) {
      toast(errMsg(err), 'error');
    }
  };

  return (
    <div className="space-y-3">
      {!crewMemberId && (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <StatCard label="Agreed crew cost" value={inr(sum.agreed)} icon={<Wallet className="h-4 w-4" />} />
            <StatCard label="Paid to crew" value={inr(sum.paid)} tone="green" icon={<IndianRupee className="h-4 w-4" />} />
            <StatCard label="Remaining to pay" value={inr(sum.remaining)} tone="amber" icon={<IndianRupee className="h-4 w-4" />} />
          </div>
          <p className="text-xs text-muted">Crew payments are business expenses. They are kept apart from client payments and are counted under Expenses in Reports.</p>
          <Card className="p-3">
            <div className="flex flex-wrap items-center gap-2">
              <SearchInput value={f.pq} onChange={(v) => setF({ pq: v })} placeholder="Search crew, event, client…" className="w-full sm:w-60" />
              <Select aria-label="Payment status" className="w-auto" value={f.pstatus} onChange={(e) => setF({ pstatus: e.target.value })} options={config.crew_payment_statuses} placeholder="Any status" />
              <input type="date" aria-label="From" className="h-9 rounded-lg border border-line bg-surface px-2 text-sm" value={f.pfrom} onChange={(e) => setF({ pfrom: e.target.value })} />
              <input type="date" aria-label="To" className="h-9 rounded-lg border border-line bg-surface px-2 text-sm" value={f.pto} onChange={(e) => setF({ pto: e.target.value })} />
              <Button className="ml-auto" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>Add payment record</Button>
            </div>
          </Card>
        </>
      )}
      <Card>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : !rows.length ? (
          <EmptyState icon={<Wallet className="h-5 w-5" />} title="No crew payments yet" description="When you schedule crew with a rate, a payment record is created here automatically." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead className="border-b border-line bg-subtle/50 text-left text-2xs uppercase tracking-wide text-muted">
                <tr>
                  <th className="w-8" />
                  {!crewMemberId && <th className="px-3 py-2">Crew member</th>}
                  <th className="px-3 py-2">Event</th>
                  <th className="px-3 py-2">Role</th>
                  <th className="px-3 py-2 text-right">Agreed</th>
                  <th className="px-3 py-2 text-right">Advance</th>
                  <th className="px-3 py-2 text-right">Paid</th>
                  <th className="px-3 py-2 text-right">Remaining</th>
                  <th className="px-3 py-2">Last paid</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="w-24" />
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {rows.map((r) => (
                  <Fragment key={r.id}>
                    <tr data-testid="crew-payment-row">
                      <td className="pl-2">
                        {r.entries?.length > 0 && (
                          <IconButton label="Show payments" onClick={() => setOpen((o) => ({ ...o, [r.id]: !o[r.id] }))}>
                            {open[r.id] ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                          </IconButton>
                        )}
                      </td>
                      {!crewMemberId && <td className="px-3 py-2.5 font-medium">{r.crew_name || '—'}{r.crew_deleted && <span className="ml-1 text-xs font-normal text-muted">(removed)</span>}</td>}
                      <td className="px-3 py-2.5">
                        <p>{r.event_name || 'Other work'}</p>
                        <p className="text-xs text-muted">{r.event_date ? fmtDate(r.event_date) : fmtDate(r.created_at)}{r.client_name ? ` · ${r.client_name}` : ''}</p>
                        {['declined', 'reassigned', 'cancelled'].includes(r.assignment_status) && <Badge tone="gray" className="mt-1">{config.crew_assignment_statuses[r.assignment_status]}</Badge>}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted">{r.role ? config.crew_categories[r.role] : '—'}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{inr(r.agreed_rate)}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums text-muted">{r.advance_paid ? inr(r.advance_paid) : '—'}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{inr(r.paid)}</td>
                      <td className="px-3 py-2.5 text-right font-medium tabular-nums">{inr(r.remaining)}</td>
                      <td className="px-3 py-2.5 text-xs text-muted">{r.payment_date ? fmtDate(r.payment_date) : '—'}</td>
                      <td className="px-3 py-2.5"><Badge tone={crewPayTone[r.payment_status]}>{config.crew_payment_statuses[r.payment_status]}</Badge></td>
                      <td className="px-3 py-2.5">
                        <div className="flex items-center justify-end gap-1">
                          {r.remaining > 0 && <Button size="xs" variant="primary" onClick={() => setPay(r)}>Pay</Button>}
                          <Menu trigger={() => <IconButton label="More"><MoreHorizontal className="h-4 w-4" /></IconButton>}>
                            {(close) => (
                              <>
                                <MenuItem icon={<Pencil />} onClick={() => (close(), setEdit(r))}>Edit agreed rate</MenuItem>
                                {!r.assignment_id && <MenuItem icon={<Trash2 />} danger onClick={() => (close(), delRecord(r))}>Delete record</MenuItem>}
                              </>
                            )}
                          </Menu>
                        </div>
                      </td>
                    </tr>
                    {open[r.id] &&
                      r.entries.map((e: any) => (
                        <tr key={'e' + e.id} className="bg-subtle/40 text-xs">
                          <td />
                          <td colSpan={crewMemberId ? 3 : 4} className="px-3 py-2 text-muted">
                            {e.payment_type === 'advance' ? 'Advance' : 'Payment'} on {fmtDate(e.paid_on)}
                            {e.method ? ` · ${config.payment_methods[e.method] || e.method}` : ''}
                            {e.reference ? ` · Ref ${e.reference}` : ''}
                            {e.note ? ` · ${e.note}` : ''}
                            {e.created_by_name ? ` · by ${e.created_by_name}` : ''}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums" colSpan={3}>{inr(e.amount)}</td>
                          <td colSpan={3} className="px-3 py-2 text-right">
                            <Button size="xs" variant="ghost" onClick={() => delEntry(e)}>Delete</Button>
                          </td>
                        </tr>
                      ))}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <PayModal rec={pay} onClose={() => setPay(null)} onDone={refresh} />
      <RecordModal rec={edit} onClose={() => setEdit(null)} onDone={refresh} crewMemberId={crewMemberId} />
    </div>
  );
}

function PayModal({ rec, onClose, onDone }: { rec: any; onClose: () => void; onDone: () => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const f = useForm<Record<string, any>>({ amount: '', paid_on: today(), payment_type: 'payment', method: 'upi', reference: '', note: '' });
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (rec) {
      f.setValues({ amount: String(rec.remaining), paid_on: today(), payment_type: rec.paid > 0 ? 'payment' : 'advance', method: 'upi', reference: '', note: '' });
      f.setErrors({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rec]);
  const save = async () => {
    const e = validate(f.values, { amount: ['required', 'positive'], paid_on: ['required'] }, { paid_on: 'Payment date' });
    if (!e.amount && Number(f.values.amount) > rec.remaining + 0.005) e.amount = `More than the remaining ${inr(rec.remaining)}.`;
    if (!e.amount && Number(f.values.amount) <= 0) e.amount = 'Enter an amount.';
    if (Object.keys(e).length) return f.setErrors(e);
    setBusy(true);
    try {
      await api.post(`crew-payments/${rec.id}/pay`, clean(f.values));
      toast(`${inr(Number(f.values.amount))} paid to ${rec.crew_name}`);
      onDone();
      onClose();
    } catch (err) {
      f.fromError(err);
      toast(errMsg(err), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={!!rec}
      onClose={onClose}
      size="sm"
      title={`Pay ${rec?.crew_name || 'crew'}`}
      subtitle={rec && `${rec.event_name || 'Other work'} · Agreed ${inr(rec.agreed_rate)} · Remaining ${inr(rec.remaining)}`}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>Record payment</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Amount (₹)" required error={f.errors.amount}><Input {...f.bind('amount')} inputMode="decimal" autoFocus /></Field>
        <Field label="Payment date" required error={f.errors.paid_on}><Input type="date" {...f.bind('paid_on')} max={dayjs().add(1, 'year').format('YYYY-MM-DD')} /></Field>
        <Field label="Type"><Select {...f.bind('payment_type')} options={{ advance: 'Advance', payment: 'Payment' }} /></Field>
        <Field label="Method" error={f.errors.method}><Select {...f.bind('method')} options={config.payment_methods} /></Field>
        <Field label="Reference" className="sm:col-span-2" error={f.errors.reference}><Input {...f.bind('reference')} placeholder="UPI ref / cheque no." /></Field>
        <Field label="Note" className="sm:col-span-2"><Input {...f.bind('note')} /></Field>
      </div>
    </Modal>
  );
}

function RecordModal({ rec, onClose, onDone, crewMemberId }: { rec: any; onClose: () => void; onDone: () => void; crewMemberId?: number }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const isNew = rec && !rec.id;
  const crew = useCrew(!!isNew);
  const f = useForm<Record<string, any>>({ crew_member_id: '', role: '', agreed_rate: '', notes: '' });
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (rec) {
      f.setValues({ crew_member_id: String(rec.crew_member_id || crewMemberId || ''), role: rec.role || '', agreed_rate: rec.agreed_rate != null ? String(rec.agreed_rate) : '', notes: rec.notes || '' });
      f.setErrors({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rec]);
  const save = async () => {
    const e = validate(f.values, isNew ? { crew_member_id: ['required'], agreed_rate: ['required', 'positive'] } : { agreed_rate: ['required', 'positive'] }, { crew_member_id: 'Crew member', agreed_rate: 'Agreed rate' });
    if (Object.keys(e).length) return f.setErrors(e);
    setBusy(true);
    try {
      if (isNew) await api.post('crew-payments', clean(f.values));
      else await api.put(`crew-payments/${rec.id}`, { agreed_rate: f.values.agreed_rate, notes: f.values.notes || null });
      toast(isNew ? 'Payment record added' : 'Agreed rate updated');
      onDone();
      onClose();
    } catch (err) {
      f.fromError(err);
      toast(errMsg(err), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={!!rec}
      onClose={onClose}
      size="sm"
      title={isNew ? 'Add crew payment record' : `Edit ${rec?.crew_name || ''}'s rate`}
      subtitle={isNew ? 'For crew work not linked to a scheduled event, like editing or a recce.' : rec?.assignment_id ? 'This also updates the rate on the crew schedule.' : undefined}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>Save</Button>
        </>
      }
    >
      <div className="grid gap-4">
        {isNew && !crewMemberId && (
          <Field label="Crew member" required error={f.errors.crew_member_id}>
            <Select {...f.bind('crew_member_id')} options={(crew.data?.items || []).map((m) => [String(m.id), m.name] as [string, string])} placeholder="Choose…" />
          </Field>
        )}
        {isNew && <Field label="Role" error={f.errors.role}><Select {...f.bind('role')} options={config.crew_categories} placeholder="Choose…" /></Field>}
        <Field label="Agreed rate (₹)" required error={f.errors.agreed_rate}><Input {...f.bind('agreed_rate')} inputMode="decimal" /></Field>
        <Field label="Notes"><Textarea rows={2} {...f.bind('notes')} /></Field>
      </div>
    </Modal>
  );
}
