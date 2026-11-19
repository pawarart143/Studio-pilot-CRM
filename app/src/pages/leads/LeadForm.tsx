import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { teamOptions, useTeam, type Lead } from '@/lib/data';
import { clean, useForm, validate } from '@/lib/form';
import { fmtDate, toLocalInput } from '@/lib/format';
import { Button, Field, Input, Modal, Select, Textarea, useUi, Badge } from '@/components/ui';
import { stageTone } from '@/lib/tones';

const empty = {
  name: '', phone: '', whatsapp: '', email: '', event_type: '', event_date: '', event_end_date: '', event_duration: '', venue: '', city: '',
  source: '', assigned_to: '', budget: '', stage: 'new', priority: 'medium', next_followup_at: '', notes: '', lost_reason: '',
};

export function LeadFormModal({ open, onClose, lead, defaults }: { open: boolean; onClose: () => void; lead?: Lead | null; defaults?: Partial<typeof empty> }) {
  const { config, member } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const nav = useNavigate();
  const team = useTeam();
  const f = useForm({ ...empty });
  const [dups, setDups] = useState<any[]>([]);
  const [sameWa, setSameWa] = useState(true);

  useEffect(() => {
    if (!open) return;
    setDups([]);
    if (lead) {
      const v: any = { ...empty };
      Object.keys(empty).forEach((k) => (v[k] = (lead as any)[k] ?? ''));
      v.assigned_to = lead.assigned_to ? String(lead.assigned_to) : '';
      v.budget = lead.budget ? String(lead.budget) : '';
      v.next_followup_at = toLocalInput(lead.next_followup_at);
      f.setValues(v);
      setSameWa(!lead.whatsapp || lead.whatsapp === lead.phone);
    } else {
      f.setValues({ ...empty, assigned_to: ['sales'].includes(member.role) ? String(member.id) : '', ...defaults });
      setSameWa(true);
    }
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, lead?.id]);

  const checkDup = async () => {
    const p = f.values.phone.trim();
    if (p.replace(/\D/g, '').length < 10) return;
    try {
      const r = await api.get('leads/duplicates', { phone: p, exclude: lead?.id });
      setDups(r);
    } catch {
      /* ignore */
    }
  };

  const save = useMutation({
    mutationFn: (force: boolean) => {
      const body: any = clean({ ...f.values, whatsapp: sameWa ? f.values.phone : f.values.whatsapp });
      if (body.stage !== 'lost') delete body.lost_reason;
      if (!body.next_followup_at) delete body.next_followup_at;
      return lead ? api.put(`leads/${lead.id}`, body) : api.post('leads', { ...body, force });
    },
    onSuccess: (res: any) => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead'] });
      qc.invalidateQueries({ queryKey: ['pipeline'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['calling-summary'] });
      toast(lead ? 'Lead updated' : 'Lead added');
      onClose();
      if (!lead) nav(`/leads/${res.id}`);
    },
    onError: (e: any) => {
      if (e instanceof ApiError && e.status === 409 && e.data.duplicates) {
        setDups(e.data.duplicates);
        return;
      }
      f.fromError(e);
      toast(e.message, 'error');
    },
  });

  const submit = (force = false) => {
    if (f.values.budget) f.values.budget = String(f.values.budget).replace(/[,\s₹]/g, '');
    const errs = validate(f.values, { name: ['required'], phone: ['required', 'phone'], email: ['email'], budget: ['positive'], ...(sameWa ? {} : { whatsapp: ['phone'] }) }, { name: 'Client name', phone: 'Phone number' });
    if (f.values.event_end_date && f.values.event_date && f.values.event_end_date < f.values.event_date) errs.event_end_date = 'Must be on or after the event date.';
    f.setErrors(errs);
    if (Object.keys(errs).length) return;
    save.mutate(force);
  };

  const b = f.bind;
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title={lead ? 'Edit lead' : 'New lead'}
      subtitle={lead ? lead.name : 'Capture a new enquiry'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          {dups.length > 0 && !lead ? (
            <Button variant="primary" loading={save.isPending} onClick={() => submit(true)}>
              Save anyway
            </Button>
          ) : (
            <Button variant="primary" loading={save.isPending} onClick={() => submit(false)}>
              {lead ? 'Save changes' : 'Add lead'}
            </Button>
          )}
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          submit(false);
        }}
        className="space-y-5"
      >
        {dups.length > 0 && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <p className="flex items-center gap-1.5 font-medium text-amber-800 dark:text-amber-300">
              <AlertTriangle className="h-4 w-4" /> Possible duplicate{dups.length > 1 ? 's' : ''} found
            </p>
            <ul className="mt-2 space-y-1">
              {dups.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center gap-2 text-amber-900 dark:text-amber-200">
                  <a href={`${window.PCRM.base}/leads/${d.id}`} target="_blank" rel="noreferrer" className="font-medium underline">
                    {d.name}
                  </a>
                  <span>{d.phone}</span>
                  <Badge tone={stageTone[d.stage]}>{config.stages[d.stage]}</Badge>
                  <span className="text-xs opacity-75">added {fmtDate(d.created_at)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        <section>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-faint">Client</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Client name" required error={f.errors.name} className="sm:col-span-2">
              <Input {...b('name')} placeholder="e.g. Ananya & Rohan Sharma" autoFocus={!lead} />
            </Field>
            <Field label="Phone number" required error={f.errors.phone}>
              <Input {...b('phone')} onBlur={checkDup} inputMode="tel" placeholder="98765 43210" />
            </Field>
            <Field label="WhatsApp number" error={f.errors.whatsapp}>
              {sameWa ? (
                <label className="flex h-[38px] items-center gap-2 text-sm text-muted">
                  <input type="checkbox" checked onChange={() => setSameWa(false)} className="h-4 w-4" /> Same as phone
                </label>
              ) : (
                <Input {...b('whatsapp')} inputMode="tel" />
              )}
            </Field>
            <Field label="Email" error={f.errors.email}>
              <Input {...b('email')} type="email" />
            </Field>
            <Field label="City" error={f.errors.city}>
              <Input {...b('city')} />
            </Field>
          </div>
        </section>
        <section>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-faint">Event</p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Field label="Event type" error={f.errors.event_type}>
              <Select {...b('event_type')} options={config.event_types} placeholder="Select" />
            </Field>
            <Field label="Event date" error={f.errors.event_date}>
              <Input {...b('event_date')} type="date" />
            </Field>
            <Field label="Event end date" error={f.errors.event_end_date}>
              <Input {...b('event_end_date')} type="date" min={f.values.event_date || undefined} />
            </Field>
            <Field label="Duration" error={f.errors.event_duration}>
              <Input {...b('event_duration')} placeholder="e.g. 3 days" />
            </Field>
            <Field label="Venue name" error={f.errors.venue} className="col-span-2">
              <Input {...b('venue')} />
            </Field>
          </div>
        </section>
        <section>
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-faint">Sales</p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Field label="Lead source" error={f.errors.source}>
              <Select {...b('source')} options={config.sources} placeholder="Select" />
            </Field>
            <Field label="Assigned sales person" error={f.errors.assigned_to}>
              <Select {...b('assigned_to')} options={teamOptions(team.data, ['owner', 'admin', 'sales'])} placeholder="Unassigned" />
            </Field>
            <Field label="Estimated budget (₹)" error={f.errors.budget}>
              <Input {...b('budget')} inputMode="numeric" placeholder="2,50,000" />
            </Field>
            <Field label="Lead status" error={f.errors.stage}>
              <Select {...b('stage')} options={config.stages} />
            </Field>
            <Field label="Priority" error={f.errors.priority}>
              <Select {...b('priority')} options={config.priorities} />
            </Field>
            <Field label="Next follow-up" error={f.errors.next_followup_at}>
              <Input {...b('next_followup_at')} type="datetime-local" />
            </Field>
            {f.values.stage === 'lost' && (
              <Field label="Lost reason" className="col-span-2 sm:col-span-3">
                <Input {...b('lost_reason')} placeholder="e.g. Budget too high" />
              </Field>
            )}
            <Field label="Notes" className="col-span-2 sm:col-span-3">
              <Textarea {...b('notes')} rows={3} />
            </Field>
          </div>
        </section>
        <button type="submit" className="hidden" />
      </form>
    </Modal>
  );
}
