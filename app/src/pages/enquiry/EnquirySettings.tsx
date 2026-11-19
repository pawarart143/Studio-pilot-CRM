import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, Copy, ExternalLink, Eye, ImagePlus, Inbox, Lock, MessageCircle, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { api, errMsg } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { fmtDate, fmtDateTime, phoneDisplay, waLink } from '@/lib/format';
import type { Tone } from '@/components/ui';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorState, Field, IconButton, Input, Modal, PageLoader, Pagination, SearchInput, Select, StatCard, TableSkeleton, Tabs, Textarea, Toggle, useUi } from '@/components/ui';

export const enquiryTone: Record<string, Tone> = { lead_created: 'green', duplicate: 'amber', merged: 'blue', separate: 'indigo', on_hold: 'orange', dismissed: 'gray' };
const CHOICE = ['select', 'radio', 'multiselect'];

type Sub = 'design' | 'questions' | 'assignment' | 'spam' | 'submissions';

export default function EnquirySettings() {
  const { toast } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['enquiry-form'], queryFn: () => api.get('enquiry-form') });
  const [sub, setSub] = useState<Sub>(() => (new URLSearchParams(window.location.search).get('etab') as Sub) || 'design');
  const [busy, setBusy] = useState(false);
  if (q.isLoading) return <PageLoader />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const form = q.data;

  const save = async (patch: Record<string, any>, msg = 'Enquiry form saved') => {
    setBusy(true);
    try {
      const r = await api.put('enquiry-form', patch);
      qc.setQueryData(['enquiry-form'], r);
      toast(msg);
      return r;
    } catch (e) {
      toast(errMsg(e), 'error');
      throw e;
    } finally {
      setBusy(false);
    }
  };
  const copy = async (text: string, msg: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast(msg);
    } catch {
      window.prompt('Copy this:', text);
    }
  };

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <div className="flex flex-wrap items-start gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-base font-semibold">Public enquiry form</h3>
              <Badge tone={form.is_enabled ? 'green' : 'gray'} dot>{form.is_enabled ? 'Live' : 'Turned off'}</Badge>
            </div>
            <p className="mt-1 text-sm text-muted">Clients fill this in from your website, Instagram bio or WhatsApp. Each enquiry becomes a lead with source “Website / Enquiry Form”.</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <code className="max-w-full truncate rounded-lg bg-subtle px-2.5 py-1.5 text-xs" data-testid="enquiry-url">{form.url}</code>
              <Button size="sm" icon={<Copy className="h-4 w-4" />} onClick={() => copy(form.url, 'Form link copied')}>Copy link</Button>
              <a className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium shadow-sm hover:bg-subtle" href={form.url + '?preview=1'} target="_blank" rel="noreferrer"><Eye className="h-4 w-4" />Preview</a>
              {form.is_enabled && <a className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium shadow-sm hover:bg-subtle" href={form.url} target="_blank" rel="noreferrer"><ExternalLink className="h-4 w-4" />Open</a>}
              <a className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium text-emerald-700 shadow-sm hover:bg-subtle dark:text-emerald-300" href={`https://wa.me/?text=${encodeURIComponent(`Share your event details with us here: ${form.url}`)}`} target="_blank" rel="noreferrer"><MessageCircle className="h-4 w-4" />Share</a>
            </div>
          </div>
          <Toggle checked={!!form.is_enabled} onChange={(v) => save({ is_enabled: v }, v ? 'Enquiry form is live' : 'Enquiry form turned off').catch(() => undefined)} label={form.is_enabled ? 'Accepting enquiries' : 'Not accepting'} />
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <StatCard label="Total enquiries" value={form.stats.total} />
          <StatCard label="This month" value={form.stats.this_month} tone="blue" />
          <StatCard label="Need review" value={form.stats.review} tone={form.stats.review ? 'amber' : 'green'} onClick={() => setSub('submissions')} />
        </div>
      </Card>
      <Tabs
        value={sub}
        onChange={setSub}
        tabs={[
          ['design', 'Page & thank-you'],
          ['questions', 'Questions'],
          ['assignment', 'Lead assignment'],
          ['spam', 'Spam protection'],
          ['submissions', 'Submissions', form.stats.review || null],
        ]}
      />
      {sub === 'design' && <DesignTab form={form} save={save} busy={busy} />}
      {sub === 'questions' && <QuestionsTab form={form} />}
      {sub === 'assignment' && <AssignTab form={form} save={save} busy={busy} />}
      {sub === 'spam' && <SpamTab form={form} save={save} busy={busy} />}
      {sub === 'submissions' && <Submissions />}
    </div>
  );
}

type SaveFn = (patch: Record<string, any>, msg?: string) => Promise<any>;

function DesignTab({ form, save, busy }: { form: any; save: SaveFn; busy: boolean }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const keys = ['title', 'description', 'brand_color', 'success_title', 'success_message', 'whatsapp_number', 'consent_text', 'slug'];
  const pick = () => Object.fromEntries(keys.map((k) => [k, form[k] ?? '']));
  const [v, setV] = useState<Record<string, string>>(pick);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [logoBusy, setLogoBusy] = useState(false);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setV(pick()), [form.id]);
  const set = (k: string, x: string) => {
    setV((s) => ({ ...s, [k]: x }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const submit = async () => {
    const e: Record<string, string> = {};
    if (!v.title.trim()) e.title = 'Form title is required.';
    if (!/^#[0-9a-fA-F]{6}$/.test(v.brand_color)) e.brand_color = 'Use a hex colour like #F3724C.';
    if (v.slug.replace(/[^a-z0-9]/gi, '').length < 3) e.slug = 'At least 3 letters or numbers.';
    if (Object.keys(e).length) return setErrors(e);
    try {
      const r = await save(Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === '' ? null : x])));
      setV(Object.fromEntries(keys.map((k) => [k, r[k] ?? ''])));
    } catch (err: any) {
      setErrors(err?.errors || {});
    }
  };
  const upload = async (file?: File | null) => {
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) return toast('Logo must be 2 MB or smaller.', 'error');
    setLogoBusy(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      await api.upload('enquiry-form/logo', fd);
      qc.invalidateQueries({ queryKey: ['enquiry-form'] });
      toast('Logo updated');
    } catch (e) {
      toast(errMsg(e), 'error');
    } finally {
      setLogoBusy(false);
    }
  };
  const removeLogo = async () => {
    await api.del('enquiry-form/logo').catch((e) => toast(errMsg(e), 'error'));
    qc.invalidateQueries({ queryKey: ['enquiry-form'] });
  };
  const base = form.url.replace(/[^/]+\/?$/, '');

  return (
    <Card className="p-4">
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-4">
          <h4 className="text-sm font-semibold">Form page</h4>
          <div className="flex items-center gap-3">
            <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-line bg-subtle">
              {form.logo_url ? <img src={form.logo_url} alt="Logo" className="h-full w-full object-contain" /> : <ImagePlus className="h-5 w-5 text-muted" />}
            </div>
            <div className="flex flex-wrap gap-2">
              <label className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium shadow-sm hover:bg-subtle">
                {logoBusy ? 'Uploading…' : form.logo_url ? 'Change logo' : 'Upload logo'}
                <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => upload(e.target.files?.[0])} />
              </label>
              {form.logo_url && <Button size="sm" variant="ghost" onClick={removeLogo}>Remove</Button>}
            </div>
          </div>
          <p className="-mt-2 text-xs text-muted">No logo? Your studio logo is used, or the studio name.</p>
          <Field label="Form title" required error={errors.title}><Input value={v.title} onChange={(e) => set('title', e.target.value)} maxLength={190} /></Field>
          <Field label="Description" error={errors.description}><Textarea rows={3} value={v.description} onChange={(e) => set('description', e.target.value)} /></Field>
          <Field label="Brand colour" error={errors.brand_color}>
            <div className="flex items-center gap-2">
              <input type="color" aria-label="Pick colour" value={/^#[0-9a-fA-F]{6}$/.test(v.brand_color) ? v.brand_color : '#F3724C'} onChange={(e) => set('brand_color', e.target.value.toUpperCase())} className="h-9 w-12 cursor-pointer rounded-lg border border-line bg-surface p-1" />
              <Input value={v.brand_color} onChange={(e) => set('brand_color', e.target.value)} className="w-32" maxLength={7} />
              <Button size="sm" variant="ghost" onClick={() => set('brand_color', '#F3724C')}>Reset</Button>
            </div>
          </Field>
          <Field label="Form address" error={errors.slug} hint={`${base}${v.slug || 'your-studio'}`}>
            <Input value={v.slug} onChange={(e) => set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))} maxLength={80} />
          </Field>
          <Field label="Consent text" error={errors.consent_text} hint="Clients must tick this before sending."><Textarea rows={2} value={v.consent_text} onChange={(e) => set('consent_text', e.target.value)} maxLength={500} /></Field>
        </div>
        <div className="space-y-4">
          <h4 className="text-sm font-semibold">Thank-you page</h4>
          <Field label="Headline" error={errors.success_title}><Input value={v.success_title} onChange={(e) => set('success_title', e.target.value)} /></Field>
          <Field label="Message" error={errors.success_message} hint="Shown under “Thank you, (client name).”"><Textarea rows={4} value={v.success_message} onChange={(e) => set('success_message', e.target.value)} /></Field>
          <Field label="WhatsApp number for the “Chat with us” button" error={errors.whatsapp_number} hint="Leave empty to use the studio phone."><Input value={v.whatsapp_number} onChange={(e) => set('whatsapp_number', e.target.value)} inputMode="tel" /></Field>
          <div className="rounded-xl border border-line p-4" style={{ borderTop: `4px solid ${/^#[0-9a-fA-F]{6}$/.test(v.brand_color) ? v.brand_color : '#F3724C'}` }}>
            <p className="text-2xs uppercase tracking-wide text-muted">Preview</p>
            <p className="mt-2 font-semibold">{v.success_title || 'Thank you!'}</p>
            <p className="mt-1 text-sm">Thank you, Priya Sharma.</p>
            <p className="mt-1 whitespace-pre-line text-sm text-muted">{v.success_message}</p>
          </div>
        </div>
      </div>
      <div className="mt-4 flex justify-end border-t border-line pt-4">
        <Button variant="primary" loading={busy} onClick={submit}>Save changes</Button>
      </div>
    </Card>
  );
}

function QuestionsTab({ form }: { form: any }) {
  const { config } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [list, setList] = useState<any[]>(form.fields);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [edit, setEdit] = useState<any>(null);
  useEffect(() => {
    setList(form.fields);
    setDirty(false);
  }, [form.fields]);
  const sections: Record<string, string> = form.sections;
  const change = (id: number, patch: any) => {
    setList((l) => l.map((f) => (f.id === id ? { ...f, ...patch } : f)));
    setDirty(true);
  };
  const move = (id: number, dir: -1 | 1) => {
    setList((l) => {
      const i = l.findIndex((f) => f.id === id);
      const sec = l[i].section;
      let j = i + dir;
      while (j >= 0 && j < l.length && l[j].section !== sec) j += dir;
      if (j < 0 || j >= l.length) return l;
      const n = [...l];
      [n[i], n[j]] = [n[j], n[i]];
      return n;
    });
    setDirty(true);
  };
  const saveAll = async () => {
    setBusy(true);
    try {
      const r = await api.put('enquiry-form/fields', { fields: list.map((f) => ({ id: f.id, label: f.label, placeholder: f.placeholder, help: f.help, is_required: !!f.is_required, is_enabled: !!f.is_enabled, show_for: f.show_for || [], options: f.options, section: f.section })) });
      qc.setQueryData(['enquiry-form'], r);
      toast('Questions saved. The public form is updated.');
    } catch (e) {
      toast(errMsg(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const remove = async (f: any) => {
    if (!(await confirm({ title: `Delete “${f.label}”?`, message: 'Answers already received stay in the submission history.', confirmLabel: 'Delete', danger: true }))) return;
    try {
      const r = await api.del(`enquiry-form/fields/${f.id}`);
      qc.setQueryData(['enquiry-form'], r);
      toast('Question deleted');
    } catch (e) {
      toast(errMsg(e), 'error');
    }
  };
  const grouped = Object.keys(sections).map((s) => [s, list.filter((f) => f.section === s)] as const);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <p className="flex-1 text-sm text-muted">Turn questions on or off, make them required, rename them and change their order. Built-in questions fill in the lead; name, phone and consent are always asked.</p>
        <Button icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({ _new: true, type: 'text', label: '', section: 'additional', is_required: false, options: [], show_for: [] })}>Add question</Button>
        <Button variant="primary" disabled={!dirty} loading={busy} onClick={saveAll}>Save changes</Button>
      </div>
      {grouped.map(([s, fields]) => (
        <Card key={s}>
          <div className="border-b border-line px-4 py-2.5 text-sm font-semibold">{sections[s]}</div>
          {!fields.length && <p className="px-4 py-3 text-sm text-muted">No questions in this step.</p>}
          <ul className="divide-y divide-line">
            {fields.map((f, i) => (
              <li key={f.id} className={clsx('flex flex-wrap items-center gap-3 px-4 py-2.5', !f.is_enabled && 'opacity-60')} data-testid="enquiry-field">
                <div className="flex flex-col">
                  <IconButton label="Move up" disabled={i === 0} onClick={() => move(f.id, -1)} className="h-6 w-6"><ArrowUp className="h-3.5 w-3.5" /></IconButton>
                  <IconButton label="Move down" disabled={i === fields.length - 1} onClick={() => move(f.id, 1)} className="h-6 w-6"><ArrowDown className="h-3.5 w-3.5" /></IconButton>
                </div>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-1.5 text-sm font-medium">
                    {f.label}
                    {f.locked && <Lock className="h-3 w-3 text-muted" />}
                  </p>
                  <p className="text-xs text-muted">
                    {form.field_types[f.type] || f.type}
                    {f.is_core ? ' · Built-in' : ' · Custom question'}
                    {f.show_for?.length ? ` · Only for ${f.show_for.map((t: string) => config.event_types[t] || t).join(', ')}` : ''}
                  </p>
                </div>
                <Checkbox checked={!!f.is_enabled} onChange={(x) => !f.locked && change(f.id, { is_enabled: x, ...(x ? {} : { is_required: false }) })} label="On" />
                <Checkbox checked={!!f.is_required} onChange={(x) => !f.locked && f.is_enabled && change(f.id, { is_required: x })} label="Required" />
                <IconButton label={`Edit ${f.label}`} onClick={() => setEdit(f)}><Pencil className="h-4 w-4" /></IconButton>
                {!f.is_core && <IconButton label={`Delete ${f.label}`} onClick={() => remove(f)}><Trash2 className="h-4 w-4" /></IconButton>}
              </li>
            ))}
          </ul>
        </Card>
      ))}
      <FieldModal
        field={edit}
        form={form}
        onClose={() => setEdit(null)}
        onApply={(f) => {
          change(f.id, f);
          setEdit(null);
        }}
        onCreated={(r) => {
          qc.setQueryData(['enquiry-form'], r);
          setEdit(null);
          toast('Question added');
        }}
      />
    </div>
  );
}

function FieldModal({ field, form, onClose, onApply, onCreated }: { field: any; form: any; onClose: () => void; onApply: (f: any) => void; onCreated: (r: any) => void }) {
  const { config } = useAuth();
  const { toast } = useUi();
  const [v, setV] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  useEffect(() => {
    if (field) setV({ ...field, optionsText: (field.options || []).join('\n') });
    setErr('');
  }, [field]);
  if (!field || !v) return null;
  const isNew = !!field._new;
  const choice = CHOICE.includes(v.type) && !field.builtin_options;
  const dateField = ['day_type', 'event_date'].includes(field.field_key);
  const apply = async () => {
    if (!v.label.trim()) return setErr('Write the question.');
    const options = choice ? String(v.optionsText).split('\n').map((s: string) => s.trim()).filter(Boolean) : v.options;
    if (choice && !options.length) return setErr('Add at least one option, one per line.');
    const out = { ...v, label: v.label.trim(), options };
    delete out.optionsText;
    if (!isNew) return onApply(out);
    setBusy(true);
    try {
      onCreated(await api.post('enquiry-form/fields', { type: out.type, label: out.label, placeholder: out.placeholder, help: out.help, section: out.section, is_required: !!out.is_required, options, show_for: out.show_for }));
    } catch (e) {
      setErr(errMsg(e));
      toast(errMsg(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  const toggleType = (t: string, on: boolean) => setV((s: any) => ({ ...s, show_for: on ? [...(s.show_for || []), t] : (s.show_for || []).filter((x: string) => x !== t) }));
  return (
    <Modal
      open
      onClose={onClose}
      title={isNew ? 'Add a question' : `Edit “${field.label}”`}
      subtitle={!isNew && field.builtin_options ? 'The choices for this built-in question come from your studio settings.' : undefined}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={apply}>{isNew ? 'Add question' : 'Apply'}</Button>
        </>
      }
    >
      <div className="grid gap-4">
        {isNew && <Field label="Answer type"><Select value={v.type} onChange={(e) => setV({ ...v, type: e.target.value })} options={form.field_types} /></Field>}
        <Field label="Question" required error={err}><Input value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} maxLength={190} autoFocus /></Field>
        {!['checkbox', 'radio', 'multiselect'].includes(v.type) && <Field label="Placeholder"><Input value={v.placeholder || ''} onChange={(e) => setV({ ...v, placeholder: e.target.value })} maxLength={190} /></Field>}
        <Field label="Help text"><Input value={v.help || ''} onChange={(e) => setV({ ...v, help: e.target.value })} maxLength={255} /></Field>
        {choice && <Field label="Options" hint="One per line."><Textarea rows={5} value={v.optionsText} onChange={(e) => setV({ ...v, optionsText: e.target.value })} /></Field>}
        {(isNew || !field.is_core) && <Field label="Step"><Select value={v.section} onChange={(e) => setV({ ...v, section: e.target.value })} options={form.sections} /></Field>}
        {isNew && <Checkbox checked={!!v.is_required} onChange={(x) => setV({ ...v, is_required: x })} label="Required" />}
        {!field.locked && !dateField && (
          <Field label="Show only for these event types" hint="Leave all unticked to always show it.">
            <div className="grid grid-cols-2 gap-1.5">
              {Object.entries(config.event_types).map(([k, l]) => (
                <Checkbox key={k} checked={(v.show_for || []).includes(k)} onChange={(x) => toggleType(k, x)} label={l} />
              ))}
            </div>
          </Field>
        )}
      </div>
    </Modal>
  );
}

function AssignTab({ form, save, busy }: { form: any; save: SaveFn; busy: boolean }) {
  const [mode, setMode] = useState(form.assign_mode || 'none');
  const [member, setMember] = useState(form.assign_member_id ? String(form.assign_member_id) : '');
  const [pool, setPool] = useState<number[]>(form.assign_pool || []);
  const [err, setErr] = useState('');
  const people: any[] = form.assignable;
  const submit = () => {
    setErr('');
    if (mode === 'fixed' && !member) return setErr('Choose who gets the new leads.');
    if (mode === 'round_robin' && !pool.length) return setErr('Choose at least one person.');
    save({ assign_mode: mode, assign_member_id: member ? Number(member) : null, assign_pool: pool }, 'Lead assignment saved').catch(() => undefined);
  };
  return (
    <Card className="max-w-2xl space-y-4 p-4">
      <p className="text-sm text-muted">Who should new enquiry leads go to? Only people who can edit leads are listed.</p>
      <div className="space-y-2">
        {[
          ['none', 'Leave unassigned', 'New leads wait in the Leads list for someone to pick up.'],
          ['fixed', 'Always the same person', 'Every enquiry goes to one person.'],
          ['round_robin', 'Round robin', 'Enquiries are shared in turn between the people you choose.'],
        ].map(([k, l, d]) => (
          <label key={k} className={clsx('flex cursor-pointer gap-3 rounded-xl border p-3', mode === k ? 'border-accent bg-accent-soft/40' : 'border-line')}>
            <input type="radio" name="assign_mode" checked={mode === k} onChange={() => setMode(k)} className="mt-1 accent-[var(--accent,#F3724C)]" />
            <span><span className="block text-sm font-medium">{l}</span><span className="text-xs text-muted">{d}</span></span>
          </label>
        ))}
      </div>
      {mode === 'fixed' && (
        <Field label="Assign to" error={err}><Select value={member} onChange={(e) => setMember(e.target.value)} options={people.map((p) => [String(p.id), p.name] as [string, string])} placeholder="Choose…" /></Field>
      )}
      {mode === 'round_robin' && (
        <Field label="Share between" error={err}>
          <div className="grid gap-1.5 sm:grid-cols-2">
            {people.map((p) => (
              <Checkbox key={p.id} checked={pool.includes(p.id)} onChange={(x) => setPool((s) => (x ? [...s, p.id] : s.filter((i) => i !== p.id)))} label={p.name} />
            ))}
          </div>
        </Field>
      )}
      {mode === 'none' && err && <p className="text-xs text-red-600">{err}</p>}
      <div className="flex justify-end"><Button variant="primary" loading={busy} onClick={submit}>Save</Button></div>
    </Card>
  );
}

function SpamTab({ form, save, busy }: { form: any; save: SaveFn; busy: boolean }) {
  const [site, setSite] = useState(form.captcha_site_key || '');
  const [secret, setSecret] = useState(form.captcha_secret || '');
  return (
    <Card className="max-w-2xl space-y-4 p-4">
      <div className="flex gap-3 rounded-xl bg-subtle/60 p-3 text-sm">
        <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
        <div>
          <p className="font-medium">Always on</p>
          <p className="text-muted">A hidden bot trap, a signed form token (too-fast or stale submissions are blocked), limits per device, per phone number and per form, and double-click protection.</p>
        </div>
      </div>
      <div>
        <h4 className="text-sm font-semibold">Cloudflare Turnstile (optional)</h4>
        <p className="text-xs text-muted">Adds a free, privacy-friendly CAPTCHA. Create a widget at dash.cloudflare.com → Turnstile and paste both keys here. Leave empty to turn it off.</p>
      </div>
      <Field label="Site key"><Input value={site} onChange={(e) => setSite(e.target.value)} placeholder="0x4AAAAAAA…" /></Field>
      <Field label="Secret key" hint={form.captcha_secret ? 'Saved. Type a new one to replace it, or clear it to remove.' : undefined}><Input type="password" value={secret} onChange={(e) => setSecret(e.target.value)} autoComplete="off" /></Field>
      <div className="flex items-center justify-between gap-2">
        <Badge tone={form.captcha_enabled ? 'green' : 'gray'} dot>{form.captcha_enabled ? 'Turnstile on' : 'Turnstile off'}</Badge>
        <Button variant="primary" loading={busy} onClick={() => save({ captcha_site_key: site.trim() || null, captcha_secret: secret }, 'Spam protection saved').catch(() => undefined)}>Save</Button>
      </div>
    </Card>
  );
}

/* ---------------- Submissions ---------------- */
export function Submissions({ initialStatus = '' }: { initialStatus?: string }) {
  const { config } = useAuth();
  const [status, setStatus] = useState(initialStatus);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const dq = useDebounce(search, 300);
  const params = { status, q: dq, page, per_page: 20 };
  const q = useQuery({ queryKey: ['enquiry-submissions', params], queryFn: () => api.get('enquiry-submissions', params), placeholderData: keepPreviousData });
  const rows: any[] = q.data?.items || [];
  const statusOpts = useMemo(() => [['review', 'Needs review'], ...Object.entries(config.enquiry_statuses)] as [string, string][], [config]);
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <SearchInput value={search} onChange={(x) => (setSearch(x), setPage(1))} placeholder="Search name, phone, email" className="w-full sm:w-64" />
        <Select aria-label="Status" className="w-auto" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))} options={statusOpts} placeholder="All enquiries" />
      </div>
      <Card>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : !rows.length ? (
          <EmptyState icon={<Inbox className="h-5 w-5" />} title={status || search ? 'No enquiries match' : 'No enquiries yet'} description={!status && !search && 'Share your form link to start receiving enquiries.'} />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead className="border-b border-line bg-subtle/50 text-left text-2xs uppercase tracking-wide text-muted">
                  <tr><th className="px-3 py-2">Received</th><th className="px-3 py-2">Name</th><th className="px-3 py-2">Event</th><th className="px-3 py-2">Campaign</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Lead</th></tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {rows.map((r) => (
                    <tr key={r.id} className="cursor-pointer hover:bg-subtle/50" onClick={() => setOpen(r.id)} data-testid="submission-row">
                      <td className="whitespace-nowrap px-3 py-2.5 text-xs text-muted">{fmtDateTime(r.created_at)}</td>
                      <td className="px-3 py-2.5"><p className="font-medium">{r.name}</p><p className="text-xs text-muted">{phoneDisplay(r.phone)}</p></td>
                      <td className="px-3 py-2.5 text-xs">{r.event_type ? config.event_types[r.event_type] || r.event_type : '—'}{r.event_date ? ` · ${fmtDate(r.event_date)}` : ''}</td>
                      <td className="px-3 py-2.5 text-xs text-muted">{[r.utm_source, r.utm_medium, r.utm_campaign].filter(Boolean).join(' / ') || '—'}</td>
                      <td className="px-3 py-2.5"><Badge tone={enquiryTone[r.status]}>{config.enquiry_statuses[r.status] || r.status}</Badge></td>
                      <td className="px-3 py-2.5 text-xs">{r.lead_id || r.duplicate_lead_id ? <Link onClick={(e) => e.stopPropagation()} to={`/leads/${r.lead_id || r.duplicate_lead_id}`} className="text-accent-ink hover:underline">{r.lead_name || 'Open lead'}</Link> : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="border-t border-line px-3 py-2"><Pagination page={page} perPage={20} total={q.data.total} onPage={setPage} /></div>
          </>
        )}
      </Card>
      <SubmissionModal id={open} onClose={() => setOpen(null)} />
    </div>
  );
}

export function SubmissionModal({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { config, can } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['enquiry-submission', id], queryFn: () => api.get(`enquiry-submissions/${id}`), enabled: !!id });
  const [busy, setBusy] = useState('');
  const s = q.data;
  const act = async (action: 'create_lead' | 'merge' | 'dismiss') => {
    const msgs = {
      create_lead: ['Create a separate lead?', 'A new lead is created from this enquiry. The existing lead is not changed.'],
      merge: ['Add to the existing lead?', 'Only empty fields on the existing lead are filled in. The enquiry is added to its notes. Nothing already on the lead is overwritten.'],
      dismiss: ['Dismiss this enquiry?', 'It stays in the submission history without a lead.'],
    } as const;
    if (!(await confirm({ title: msgs[action][0], message: msgs[action][1], confirmLabel: 'Confirm', danger: action === 'dismiss' }))) return;
    setBusy(action);
    try {
      const r = await api.post(`enquiry-submissions/${id}/resolve`, { action });
      qc.setQueryData(['enquiry-submission', id], r);
      qc.invalidateQueries({ queryKey: ['enquiry-submissions'] });
      qc.invalidateQueries({ queryKey: ['enquiry-form'] });
      qc.invalidateQueries({ queryKey: ['leads'] });
      toast(action === 'dismiss' ? 'Enquiry dismissed' : action === 'merge' ? 'Added to the existing lead' : 'New lead created');
    } catch (e) {
      toast(errMsg(e), 'error');
    } finally {
      setBusy('');
    }
  };
  const bySection = useMemo(() => {
    const m: Record<string, any[]> = {};
    (s?.answers || []).forEach((a: any) => (m[a.section || 'additional'] ||= []).push(a));
    return m;
  }, [s]);
  const sections: Record<string, string> = { client: 'Client details', event: 'Event details', services: 'Photography services', budget: 'Budget', additional: 'Additional information' };
  const review = s && ['duplicate', 'on_hold'].includes(s.status);
  return (
    <Modal
      open={!!id}
      onClose={onClose}
      size="lg"
      title={s ? `${s.name} · ${s.reference}` : 'Enquiry'}
      subtitle={s && `Received ${fmtDateTime(s.created_at)}`}
      footer={
        review && can('leads.edit') ? (
          <>
            <Button variant="ghost" loading={busy === 'dismiss'} onClick={() => act('dismiss')}>Dismiss</Button>
            <Button loading={busy === 'create_lead'} onClick={() => act('create_lead')}>Create separate lead</Button>
            {s.duplicate_lead && <Button variant="primary" loading={busy === 'merge'} onClick={() => act('merge')}>Add to existing lead</Button>}
          </>
        ) : (
          <Button onClick={onClose}>Close</Button>
        )
      }
    >
      {q.isLoading ? (
        <PageLoader />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : s ? (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={enquiryTone[s.status]}>{config.enquiry_statuses[s.status] || s.status}</Badge>
            {s.lead && <Link to={`/leads/${s.lead.id}`} onClick={onClose} className="text-sm text-accent-ink hover:underline">Open lead: {s.lead.name}</Link>}
            <a href={waLink(s.phone)} target="_blank" rel="noreferrer" className="text-sm text-emerald-700 hover:underline dark:text-emerald-300">WhatsApp {phoneDisplay(s.phone)}</a>
          </div>
          {review && s.duplicate_lead && (
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
              <p className="font-medium">A lead with this phone number already exists</p>
              <p className="mt-1 text-xs">
                <Link to={`/leads/${s.duplicate_lead.id}`} onClick={onClose} className="font-medium text-accent-ink hover:underline">{s.duplicate_lead.name}</Link> · {config.stages[s.duplicate_lead.stage] || s.duplicate_lead.stage} · created {fmtDate(s.duplicate_lead.created_at)}
                {s.duplicate_lead.event_date ? ` · event ${fmtDate(s.duplicate_lead.event_date)}` : ''}
              </p>
              <p className="mt-1 text-xs text-muted">Nothing was changed on it. Choose what to do with this enquiry below.</p>
            </div>
          )}
          {review && s.status === 'on_hold' && <p className="rounded-xl bg-subtle p-3 text-sm">This enquiry arrived after your plan's monthly lead limit was reached. Create the lead when you have room, or upgrade your plan.</p>}
          {Object.keys(sections).filter((k) => bySection[k]).map((k) => (
            <div key={k}>
              <h4 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted">{sections[k]}</h4>
              <dl className="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-[180px_1fr]">
                {bySection[k].map((a: any) => (
                  <div key={a.key} className="contents">
                    <dt className="text-muted">{a.label}</dt>
                    <dd className="whitespace-pre-line break-words">{a.value || '—'}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ))}
          <div className="rounded-xl bg-subtle/60 p-3 text-xs text-muted">
            <p>Form: {s.form_url || '—'}</p>
            {s.referrer && <p>Came from: {s.referrer}</p>}
            {(s.utm_source || s.utm_medium || s.utm_campaign) && <p>UTM: {[s.utm_source, s.utm_medium, s.utm_campaign].filter(Boolean).join(' / ')}</p>}
            {s.handled_at && <p>Handled {fmtDateTime(s.handled_at)}</p>}
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
