import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam } from '@/lib/data';
import { useForm, validate } from '@/lib/form';
import { inr, toLocalInput, today } from '@/lib/format';
import { Button, Field, Input, Modal, Select, Textarea, useUi } from './ui';

const money = (v: any) => {
  const n = parseFloat(String(v ?? '').replace(/[₹,\s]/g, ''));
  return isFinite(n) ? n : NaN;
};
const invalidateMoney = (qc: ReturnType<typeof useQueryClient>) => ['booking', 'bookings', 'payments', 'payments-dues', 'payments-summary', 'dashboard', 'reports', 'client', 'expenses'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));

/* ---------- Add / edit payment (owner & admin only) ---------- */
export function PaymentModal({ open, onClose, booking, payment, pickBooking }: { open: boolean; onClose: () => void; booking: { id: number; number: string; title: string; payment: any } | null; payment?: any; pickBooking?: boolean }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const isEdit = !!payment?.id;
  const f = useForm({ amount: '', paid_on: today(), method: 'upi', transaction_ref: '', installment_id: '', payment_type: '', status: 'received', notes: '' });
  const [busy, setBusy] = useState(false);
  const [bookingId, setBookingId] = useState<string>('');
  const dues = useQuery({ queryKey: ['payments-dues', 'all', 'pick'], queryFn: () => api.get<any[]>('payments/dues', { status: 'all' }), enabled: open && !!pickBooking && !isEdit });
  const picked = pickBooking && !isEdit ? (dues.data || []).find((b) => String(b.id) === bookingId) : null;
  const bk = picked ? { id: picked.id, number: picked.number, title: picked.title, payment: { pending: picked.pending, installments: picked.installments, total: picked.total_amount } } : booking;
  // When editing, this payment's own amount is available again.
  const ownBack = isEdit && ['received', 'pending'].includes(payment.status) ? Number(payment.amount) : 0;
  const pending = (bk?.payment?.pending ?? 0) + ownBack;
  const instAll = bk?.payment?.installments || [];
  const open_inst = isEdit ? instAll : instAll.filter((i: any) => i.status !== 'paid');
  useEffect(() => {
    if (!open) return;
    if (isEdit) {
      f.setValues({ amount: String(payment.amount), paid_on: payment.paid_on, method: payment.method, transaction_ref: payment.transaction_ref || '', installment_id: payment.installment_id ? String(payment.installment_id) : '', payment_type: payment.payment_type || '', status: payment.status || 'received', notes: payment.notes || '' });
    } else {
      const next = open_inst[0];
      f.setValues({ amount: next ? String(next.balance) : pending ? String(pending) : '', paid_on: today(), method: 'upi', transaction_ref: '', installment_id: next ? String(next.id) : '', payment_type: '', status: 'received', notes: '' });
    }
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, bk?.id, payment?.id]);
  useEffect(() => {
    if (!open) setBookingId('');
  }, [open]);

  const save = async () => {
    const v = f.values;
    const e = validate(v, { amount: ['required', 'positive'], paid_on: ['required'], method: ['required'] }, { paid_on: 'Payment date' });
    if (!bk) e.booking = 'Choose a booking.';
    const amt = money(v.amount);
    if (!e.amount && !(amt > 0)) e.amount = 'Enter an amount.';
    if (!e.amount && bk?.payment && ['received', 'pending'].includes(v.status) && amt - pending > 0.009) e.amount = `Only ${inr(pending)} is pending on this booking.`;
    if (!e.paid_on && v.paid_on > today()) e.paid_on = 'Payment date cannot be in the future.';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const body = { ...v, amount: amt, installment_id: v.installment_id || null, payment_type: v.payment_type || null, transaction_ref: v.transaction_ref || null };
    try {
      const r = isEdit ? await api.put(`payments/${payment.id}`, body) : await api.post(`bookings/${bk!.id}/payments`, body);
      toast(isEdit ? `Payment ${r.receipt_no} updated` : `Payment of ${inr(amt)} recorded · Receipt ${r.receipt_no}`);
      invalidateMoney(qc);
      qc.invalidateQueries({ queryKey: ['payment-activity'] });
      qc.invalidateQueries({ queryKey: ['payment'] });
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? `Edit payment ${payment.receipt_no}` : 'Add payment'}
      subtitle={isEdit ? `${payment.client_name || ''} · ${payment.booking_number || booking?.number || ''}` : bk ? `${bk.number} · ${bk.title} · ${inr(bk.payment?.pending ?? 0)} pending` : 'Choose the booking first'}
      size="sm"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>{isEdit ? 'Save changes' : 'Save payment'}</Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        {pickBooking && !isEdit && (
          <Field label="Booking" required error={f.errors.booking} className="col-span-2">
            <Select value={bookingId} onChange={(e) => setBookingId(e.target.value)} options={(dues.data || []).map((b) => [String(b.id), `${b.client_name} · ${b.number} · ${inr(b.pending)} pending`] as [string, string])} placeholder={dues.isLoading ? 'Loading bookings…' : 'Choose booking…'} />
          </Field>
        )}
        {bk?.payment?.total !== undefined && (
          <div className="col-span-2 flex justify-between rounded-lg bg-subtle px-3 py-2 text-xs"><span className="text-muted">Total package amount</span><b className="tabular">{inr(bk.payment.total)}</b></div>
        )}
        <Field label="Payment amount (₹)" required error={f.errors.amount} className="col-span-2">
          <Input {...f.bind('amount')} inputMode="decimal" autoFocus={!pickBooking} />
        </Field>
        <Field label="Payment date" required error={f.errors.paid_on}>
          <Input type="date" max={today()} {...f.bind('paid_on')} />
        </Field>
        <Field label="Method" required error={f.errors.method}>
          <Select {...f.bind('method')} options={config.payment_methods} />
        </Field>
        <Field label="Payment type">
          <Select {...f.bind('payment_type')} options={config.payment_types} placeholder="Auto" />
        </Field>
        <Field label="Status" hint={f.values.status !== 'received' ? 'Not counted as paid' : undefined}>
          <Select {...f.bind('status')} options={config.payment_statuses} />
        </Field>
        {open_inst.length > 0 && (
          <Field label="Against installment" className="col-span-2">
            <Select {...f.bind('installment_id')} options={open_inst.map((i: any) => [String(i.id), `${i.label} · ${inr(isEdit ? i.amount : i.balance)}${isEdit ? '' : ' due'}`] as [string, string])} placeholder="Not linked" />
          </Field>
        )}
        <Field label="Transaction ID" className="col-span-2" error={f.errors.transaction_ref}>
          <Input {...f.bind('transaction_ref')} placeholder="UPI ref, cheque no., bank ref" />
        </Field>
        <Field label="Notes" className="col-span-2">
          <Textarea rows={2} {...f.bind('notes')} />
        </Field>
      </div>
    </Modal>
  );
}

/* ---------- Expense ---------- */
export function ExpenseModal({ open, onClose, expense, bookingId }: { open: boolean; onClose: () => void; expense?: any; bookingId?: number }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const f = useForm({ category: 'travel', description: '', amount: '', spent_on: today(), method: 'upi', booking_id: '' });
  const [busy, setBusy] = useState(false);
  const bookings = useQuery({ queryKey: ['bookings', 'pick'], queryFn: () => api.get('bookings', { per_page: 100 }), enabled: open && !bookingId });
  useEffect(() => {
    if (!open) return;
    f.setValues(
      expense
        ? { category: expense.category, description: expense.description || '', amount: String(expense.amount), spent_on: expense.spent_on, method: expense.method || '', booking_id: expense.booking_id ? String(expense.booking_id) : '' }
        : { category: 'travel', description: '', amount: '', spent_on: today(), method: 'upi', booking_id: bookingId ? String(bookingId) : '' },
    );
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, expense?.id]);
  const save = async () => {
    const e = validate(f.values, { category: ['required'], amount: ['required', 'positive'], spent_on: ['required'] }, { spent_on: 'Date' });
    if (!e.amount && !(money(f.values.amount) > 0)) e.amount = 'Enter an amount.';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const body = { ...f.values, amount: money(f.values.amount), booking_id: f.values.booking_id || null, method: f.values.method || null };
    try {
      if (expense) await api.put(`expenses/${expense.id}`, body);
      else await api.post('expenses', body);
      toast(expense ? 'Expense updated' : 'Expense added');
      invalidateMoney(qc);
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={expense ? 'Edit expense' : 'Add expense'}
      size="sm"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>Save</Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        <Field label="Category" required error={f.errors.category}><Select {...f.bind('category')} options={config.expense_categories} /></Field>
        <Field label="Amount (₹)" required error={f.errors.amount}><Input {...f.bind('amount')} inputMode="decimal" autoFocus /></Field>
        <Field label="Date" required error={f.errors.spent_on}><Input type="date" {...f.bind('spent_on')} /></Field>
        <Field label="Paid by"><Select {...f.bind('method')} options={config.payment_methods} placeholder="—" /></Field>
        <Field label="Description" className="col-span-2"><Input {...f.bind('description')} placeholder="e.g. Cab to venue, second shooter fee" /></Field>
        {!bookingId && (
          <Field label="Booking (optional)" className="col-span-2">
            <Select {...f.bind('booking_id')} options={(bookings.data?.items || []).map((b: any) => [String(b.id), `${b.number} · ${b.title}`] as [string, string])} placeholder="General studio expense" />
          </Field>
        )}
      </div>
    </Modal>
  );
}

/* ---------- Task ---------- */
export function TaskModal({ open, onClose, task, defaults }: { open: boolean; onClose: () => void; task?: any; defaults?: { lead_id?: number; booking_id?: number; assigned_to?: number } }) {
  const { config, member } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const team = useTeam();
  const init = { title: '', description: '', category: 'other', priority: 'medium', status: 'todo', due_at: '', assigned_to: '' };
  const f = useForm(init);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!open) return;
    f.setValues(
      task
        ? { title: task.title, description: task.description || '', category: task.category, priority: task.priority, status: task.status, due_at: toLocalInput(task.due_at), assigned_to: task.assigned_to ? String(task.assigned_to) : '' }
        : { ...init, assigned_to: String(defaults?.assigned_to || member.id) },
    );
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, task?.id]);
  const save = async () => {
    const e = validate(f.values, { title: ['required'] });
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    const body = { ...f.values, due_at: f.values.due_at || null, assigned_to: f.values.assigned_to || null, ...(task ? {} : { lead_id: defaults?.lead_id || null, booking_id: defaults?.booking_id || null }) };
    try {
      if (task) await api.put(`tasks/${task.id}`, body);
      else await api.post('tasks', body);
      toast(task ? 'Task updated' : 'Task added');
      ['tasks', 'booking', 'lead', 'dashboard', 'team'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={task ? 'Edit task' : 'New task'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>Save task</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Task" required error={f.errors.title} className="sm:col-span-2"><Input {...f.bind('title')} placeholder="e.g. Send edited highlights to client" autoFocus /></Field>
        <Field label="Category"><Select {...f.bind('category')} options={config.task_categories} /></Field>
        <Field label="Assigned to"><Select {...f.bind('assigned_to')} options={teamOptions(team.data)} placeholder="Unassigned" /></Field>
        <Field label="Deadline" error={f.errors.due_at}><Input type="datetime-local" {...f.bind('due_at')} /></Field>
        <Field label="Priority"><Select {...f.bind('priority')} options={config.priorities} /></Field>
        {task && <Field label="Status"><Select {...f.bind('status')} options={config.task_statuses} /></Field>}
        <Field label="Details" className="sm:col-span-2"><Textarea rows={3} {...f.bind('description')} /></Field>
      </div>
    </Modal>
  );
}
