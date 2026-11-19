import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Camera, KeyRound, MapPin, Pencil, Phone, Plus, Trash2, UserX, UserCheck, Users } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useForm, validate } from '@/lib/form';
import { useUrlState } from '@/lib/hooks';
import { availabilityTone } from '@/lib/tones';
import { dayjs, fmtTime, inrShort, phoneDisplay, telLink } from '@/lib/format';
import { Avatar, Badge, Button, Card, Checkbox, EmptyState, ErrorState, Field, Input, Modal, PageHeader, SearchInput, Select, TableSkeleton, Tabs, Textarea, useUi } from '@/components/ui';

const ROLE_ACCESS: Record<string, string> = {
  admin: 'everything, including payments, reports and settings',
  sales: 'all leads, calling, quotations, bookings and payment status (cannot record payments)',
  production_manager: 'all bookings, team assignments, calendar and every task (no money)',
};
const COLORS = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#2563eb', '#65a30d'];

export default function Team() {
  const { config, can, studio, member: me } = useAuth();
  const { toast, confirm } = useUi();
  const qc = useQueryClient();
  const [f, setF] = useUrlState({ tab: 'members', q: '', trole: '', avail: '', show: 'active', from: dayjs().format('YYYY-MM-DD'), pfrom: dayjs().startOf('month').format('YYYY-MM-DD'), pto: dayjs().format('YYYY-MM-DD') });
  const [edit, setEdit] = useState<any>(null);
  const list = useQuery({ queryKey: ['team', 'all'], queryFn: () => api.get<any[]>('team') });
  const avail = useQuery({ queryKey: ['availability', f.from], queryFn: () => api.get('team/availability', { from: f.from, to: dayjs(f.from).add(13, 'day').format('YYYY-MM-DD') }), enabled: f.tab === 'availability' });
  const perf = useQuery({ queryKey: ['performance', f.pfrom, f.pto], queryFn: () => api.get('team/performance', { from: f.pfrom, to: f.pto }), enabled: f.tab === 'performance' });
  const members = list.data || [];
  const active = members.filter((m) => m.status === 'active');
  const plan = studio.plan_info;

  const shown = members.filter((m) => {
    if (f.show !== 'all' && m.status !== f.show) return false;
    if (f.trole && m.team_role !== f.trole) return false;
    if (f.avail && (m.availability || 'available') !== f.avail) return false;
    if (f.q) {
      const hay = [m.name, m.phone, m.email, m.city, m.specialization, m.team_role ? config.team_roles[m.team_role] : ''].join(' ').toLowerCase();
      if (!hay.includes(f.q.toLowerCase())) return false;
    }
    return true;
  });
  const setActive = async (m: any, on: boolean) => {
    if (!on && !(await confirm({ title: `Deactivate ${m.name}?`, message: 'They stay in past bookings but cannot be assigned to new work' + (m.user_id ? ' and cannot sign in to this studio.' : '.'), confirmLabel: 'Deactivate', danger: true }))) return;
    try {
      await api.put(`team/${m.id}`, { status: on ? 'active' : 'inactive' });
      toast(on ? `${m.name} is active again` : `${m.name} deactivated`);
      qc.invalidateQueries({ queryKey: ['team'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  const remove = async (m: any) => {
    if (!(await confirm({ title: `Remove ${m.name}?`, message: 'If they have event history they will be deactivated instead, so past bookings keep their team details.', danger: true, confirmLabel: 'Remove' }))) return;
    try {
      const r = await api.del(`team/${m.id}`);
      toast(r.message || 'Team member removed');
      qc.invalidateQueries({ queryKey: ['team'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const days = Array.from({ length: 14 }, (_, i) => dayjs(f.from).add(i, 'day'));

  return (
    <div>
      <PageHeader
        title="Photographer & creative team"
        subtitle={`${active.length} active member${active.length === 1 ? '' : 's'} · ${active.filter((m) => m.user_id).length} with a login${plan.members > 0 ? ` (your ${plan.label} plan allows ${plan.members})` : ''}. Crew without a login can be added freely for scheduling.`}
        actions={can('team.edit') && <Button variant="primary" icon={<Plus className="h-4 w-4" />} onClick={() => setEdit({})}>Add member</Button>}
      />
      <Card>
        <div className="px-4 pt-1">
          <Tabs value={f.tab} onChange={(tab) => setF({ tab })} tabs={[['members', 'Directory', active.length], ['availability', 'Availability'], ...(can('reports.view') ? ([['performance', 'Performance']] as [string, string][]) : [])]} />
        </div>

        {f.tab === 'members' && (
          <>
            <div className="flex flex-col gap-2 border-b border-line p-3 lg:flex-row lg:items-center">
              <SearchInput value={f.q} onChange={(q) => setF({ q })} placeholder="Name, phone, city, skill…" className="w-full lg:w-64" />
              <div className="flex flex-wrap gap-2 lg:ml-auto">
                <Select value={f.trole} onChange={(e) => setF({ trole: e.target.value })} options={config.team_roles} placeholder="All roles" className="w-auto py-1.5" />
                <Select value={f.avail} onChange={(e) => setF({ avail: e.target.value })} options={config.availability} placeholder="Any availability" className="w-auto py-1.5" />
                <Select value={f.show} onChange={(e) => setF({ show: e.target.value })} options={[['active', 'Active'], ['inactive', 'Deactivated'], ['all', 'Active + deactivated']]} className="w-auto py-1.5" />
              </div>
            </div>
            {list.error ? (
              <ErrorState error={list.error} onRetry={() => list.refetch()} />
            ) : list.isLoading ? (
              <TableSkeleton />
            ) : !shown.length ? (
              <EmptyState icon={<Users className="h-5 w-5" />} title={members.length ? 'No one matches these filters' : 'No team members yet'} action={can('team.edit') && !members.length && <Button variant="primary" onClick={() => setEdit({})}>Add your first photographer</Button>} />
            ) : (
              <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
                {shown.map((m) => (
                  <div key={m.id} className={clsx('group relative rounded-xl border border-line p-4 transition-shadow hover:shadow-card', m.status !== 'active' && 'opacity-60')}>
                    <Link to={`/team/${m.id}`} className="absolute inset-0 rounded-xl" aria-label={`Open ${m.name}`} />
                    <div className="flex items-start gap-3">
                      <Avatar name={m.name} color={m.color} src={m.photo_url} size="lg" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{m.name}{m.id === me.id && <span className="ml-1 text-xs font-normal text-muted">(you)</span>}</p>
                        <p className="truncate text-xs text-muted">{m.team_role ? config.team_roles[m.team_role] : config.roles[m.role]}</p>
                        <div className="mt-1.5 flex flex-wrap gap-1">
                          {m.status !== 'active' ? <Badge>Deactivated</Badge> : <Badge tone={availabilityTone[m.availability || 'available']} dot>{config.availability[m.availability || 'available']}</Badge>}
                          {m.has_login || m.user_id ? <Badge tone="blue">{config.roles[m.role]} login</Badge> : null}
                        </div>
                      </div>
                    </div>
                    {m.specialization && <p className="mt-3 line-clamp-1 text-xs text-muted">{m.specialization}</p>}
                    <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                      {m.city && <span className="inline-flex items-center gap-1"><MapPin className="h-3 w-3" />{m.city}</span>}
                      {m.phone && <a href={telLink(m.phone)} className="relative z-10 inline-flex items-center gap-1 hover:text-accent-ink"><Phone className="h-3 w-3" />{phoneDisplay(m.phone)}</a>}
                    </div>
                    <div className="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-center">
                      <div><p className="tabular text-sm font-semibold">{m.upcoming_events || 0}</p><p className="text-2xs text-muted">Upcoming shoots</p></div>
                      <div><p className="tabular text-sm font-semibold">{m.open_deliverables || 0}</p><p className="text-2xs text-muted">Open deliverables</p></div>
                      <div><p className="tabular text-sm font-semibold">{m.open_tasks || 0}</p><p className="text-2xs text-muted">Open tasks</p></div>
                    </div>
                    {can('team.edit') && (
                      <div className="absolute right-2 top-2 z-10 flex gap-0.5 opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                        <button onClick={() => setEdit(m)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Edit"><Pencil className="h-4 w-4" /></button>
                        {m.role !== 'owner' && m.id !== me.id && (m.status === 'active' ? <button onClick={() => setActive(m, false)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Deactivate" title="Deactivate"><UserX className="h-4 w-4" /></button> : <button onClick={() => setActive(m, true)} className="rounded p-1.5 text-faint hover:bg-subtle hover:text-ink" aria-label="Activate" title="Activate"><UserCheck className="h-4 w-4" /></button>)}
                        {m.role !== 'owner' && m.id !== me.id && <button onClick={() => remove(m)} className="rounded p-1.5 text-faint hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" aria-label="Remove"><Trash2 className="h-4 w-4" /></button>}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </>
        )}

        {f.tab === 'availability' && (
          <>
            <div className="flex items-center gap-2 border-b border-line p-3">
              <Button size="sm" onClick={() => setF({ from: dayjs(f.from).subtract(14, 'day').format('YYYY-MM-DD') })}>← Prev</Button>
              <Input type="date" value={f.from} onChange={(e) => e.target.value && setF({ from: e.target.value })} className="w-auto py-1.5" />
              <Button size="sm" onClick={() => setF({ from: dayjs(f.from).add(14, 'day').format('YYYY-MM-DD') })}>Next →</Button>
              <span className="ml-auto hidden text-xs text-muted sm:block">Coloured cells are booked shoots. Hover for details.</span>
            </div>
            {avail.isLoading ? (
              <TableSkeleton />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] border-collapse text-xs">
                  <thead>
                    <tr className="border-b border-line bg-subtle/50">
                      <th className="th sticky left-0 z-10 bg-subtle">Member</th>
                      {days.map((d) => (
                        <th key={d.format()} className={clsx('px-1 py-2 text-center font-medium', [0, 6].includes(d.day()) && 'bg-subtle')}>
                          <span className="block text-2xs text-muted">{d.format('ddd')}</span>
                          {d.format('DD')}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {(avail.data?.members || []).map((m: any) => (
                      <tr key={m.id}>
                        <td className="sticky left-0 z-10 whitespace-nowrap bg-surface px-3 py-2"><span className="flex items-center gap-2"><Avatar name={m.name} color={m.color} size="xs" />{m.name}</span></td>
                        {days.map((d) => {
                          const ds = d.format('YYYY-MM-DD');
                          const evs = m.busy.filter((b: any) => b.event_date === ds);
                          return (
                            <td key={ds} className="p-0.5 text-center">
                              {evs.length ? (
                                <Link to={`/bookings/${evs[0].booking_id}`} title={evs.map((e: any) => `${e.booking_title} – ${e.name}${e.start_time ? ' ' + fmtTime(e.start_time) : ''}${e.city ? ', ' + e.city : ''}`).join('\n')} className="block rounded px-1 py-1.5 text-2xs font-medium text-white" style={{ background: m.color || '#4f46e5' }}>
                                  {evs.length > 1 ? `${evs.length} shoots` : (evs[0].city || 'Shoot')}
                                </Link>
                              ) : (
                                <span className="block rounded py-1.5 text-2xs text-faint">—</span>
                              )}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

        {f.tab === 'performance' && (
          <>
            <div className="flex flex-wrap items-center gap-2 border-b border-line p-3">
              <Input type="date" value={f.pfrom} onChange={(e) => setF({ pfrom: e.target.value })} className="w-auto py-1.5" aria-label="From" />
              <span className="text-xs text-muted">to</span>
              <Input type="date" value={f.pto} onChange={(e) => setF({ pto: e.target.value })} className="w-auto py-1.5" aria-label="To" />
              <Button size="sm" variant="ghost" onClick={() => setF({ pfrom: dayjs().startOf('month').format('YYYY-MM-DD'), pto: dayjs().format('YYYY-MM-DD') })}>This month</Button>
              <Button size="sm" variant="ghost" onClick={() => setF({ pfrom: dayjs().subtract(2, 'month').startOf('month').format('YYYY-MM-DD'), pto: dayjs().format('YYYY-MM-DD') })}>Last 3 months</Button>
            </div>
            {perf.isLoading ? (
              <TableSkeleton />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Member</th><th className="th text-right">Leads</th><th className="th text-right">Calls</th><th className="th text-right">Booked</th><th className="th text-right">Conversion</th><th className="th text-right">Revenue</th><th className="th text-right">Shoots</th><th className="th text-right">Tasks done</th><th className="th text-right">Overdue tasks</th></tr></thead>
                  <tbody className="divide-y divide-line">
                    {(perf.data?.members || []).map((m: any) => (
                      <tr key={m.id}>
                        <td className="td"><span className="flex items-center gap-2"><Avatar name={m.name} color={m.color} size="sm" /><span><span className="font-medium">{m.name}</span><span className="block text-xs text-muted">{config.roles[m.role]}</span></span></span></td>
                        <td className="td tabular text-right">{m.leads}</td>
                        <td className="td tabular text-right">{m.calls}</td>
                        <td className="td tabular text-right">{m.booked}</td>
                        <td className="td tabular text-right">{m.leads ? m.conversion + '%' : '—'}</td>
                        <td className="td tabular text-right">{m.revenue ? inrShort(m.revenue) : '—'}</td>
                        <td className="td tabular text-right">{m.events}</td>
                        <td className="td tabular text-right">{m.tasks_done}</td>
                        <td className={clsx('td tabular text-right', m.tasks_overdue > 0 && 'font-medium text-red-600')}>{m.tasks_overdue}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </Card>
      <MemberModal member={edit} onClose={() => setEdit(null)} />
    </div>
  );
}

export function MemberModal({ member, onClose }: { member: any; onClose: () => void }) {
  const { config, refresh } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const isNew = member && !member.id;
  const blank = { name: '', email: '', phone: '', role: 'photographer', status: 'active', color: COLORS[0], create_login: false, password: '', team_role: '', specialization: '', city: '', availability: 'available', joining_date: '', notes: '' };
  const f = useForm(blank);
  const [photo, setPhoto] = useState<File | null>(null);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!member) return;
    f.setValues(
      member.id
        ? { ...blank, name: member.name, email: member.email || '', phone: member.phone || '', role: member.role, status: member.status, color: member.color || COLORS[0], team_role: member.team_role || '', specialization: member.specialization || '', city: member.city || '', availability: member.availability || 'available', joining_date: member.joining_date || '', notes: member.notes || '' }
        : { ...blank, color: COLORS[Math.floor(Math.random() * COLORS.length)] },
    );
    setPhoto(null);
    setPhotoUrl(member.photo_url || null);
    f.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [member]);
  const hasLogin = member?.user_id;
  const roles = Object.fromEntries(Object.entries(config.roles).filter(([k]) => k !== 'owner'));
  const save = async () => {
    const v = f.values;
    const e = validate(v, { name: ['required'], email: v.create_login ? ['required', 'email'] : ['email'], phone: ['phone'] }, { email: 'Email' });
    if (v.create_login && v.password && v.password.length < 8) e.password = 'At least 8 characters.';
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    if (photo && photo.size > 3 * 1024 * 1024) e.photo = 'Photo must be smaller than 3 MB.';
    if (Object.keys(e).length) return f.setErrors(e);
    const body: any = { ...v, email: v.email || null, phone: v.phone || null, team_role: v.team_role || null, joining_date: v.joining_date || null };
    if (member.role === 'owner') delete body.role;
    if (!v.create_login) delete body.password;
    try {
      const r = isNew ? await api.post('team', body) : await api.put(`team/${member.id}`, body);
      if (photo) {
        const fd = new FormData();
        fd.append('file', photo);
        try {
          await api.upload(`team/${r.id}/photo`, fd);
        } catch (pe: any) {
          toast('Saved, but the photo did not upload: ' + pe.message, 'error');
        }
      } else if (!isNew && member.photo_url && !photoUrl) {
        await api.del(`team/${member.id}/photo`).catch(() => undefined);
      }
      const login = r.login;
      toast(isNew ? `${v.name} added${login?.created ? (login.mailed ? ' · login details emailed' : ' · login created (email could not be sent — share the password yourself)') : login?.linked ? ' · linked to existing account' : ''}` : 'Member updated');
      qc.invalidateQueries({ queryKey: ['team'] });
      refresh();
      onClose();
    } catch (err) {
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal
      open={!!member}
      onClose={onClose}
      title={isNew ? 'Add team member' : `Edit ${member?.name}`}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>{isNew ? 'Add member' : 'Save'}</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex items-center gap-3 sm:col-span-2">
          <Avatar name={f.values.name || 'New'} color={f.values.color} src={photo ? URL.createObjectURL(photo) : photoUrl} size="lg" />
          <div className="flex flex-wrap gap-2">
            <label className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-xs font-medium shadow-sm hover:bg-subtle">
              <Camera className="h-4 w-4" />{photo || photoUrl ? 'Change photo' : 'Add profile photo'}
              <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => setPhoto(e.target.files?.[0] || null)} />
            </label>
            {(photo || photoUrl) && <Button size="sm" variant="ghost" onClick={() => (setPhoto(null), setPhotoUrl(null))}>Remove</Button>}
          </div>
          {f.errors.photo && <p className="text-xs text-red-600">{f.errors.photo}</p>}
        </div>
        <Field label="Full name" required error={f.errors.name} className="sm:col-span-2"><Input {...f.bind('name')} autoFocus /></Field>
        <Field label="Team role" hint="What they do on shoots and in post-production.">
          <Select {...f.bind('team_role')} options={config.team_roles} placeholder="Choose…" />
        </Field>
        <Field label="Availability"><Select {...f.bind('availability')} options={config.availability} /></Field>
        <Field label="Specialization" className="sm:col-span-2"><Input {...f.bind('specialization')} placeholder="Candid, destination weddings, drone licence…" /></Field>
        <Field label="City"><Input {...f.bind('city')} placeholder="Pune" /></Field>
        <Field label="Joining date"><Input type="date" {...f.bind('joining_date')} /></Field>
        <Field label="CRM access" required error={f.errors.role} hint="Decides what they can see if they sign in.">
          {member?.role === 'owner' ? <Input value="Owner" disabled /> : <Select {...f.bind('role')} options={roles} />}
        </Field>
        {!isNew && member?.role !== 'owner' && <Field label="Status"><Select {...f.bind('status')} options={{ active: 'Active', inactive: 'Inactive' }} /></Field>}
        <Field label="Phone" error={f.errors.phone}><Input {...f.bind('phone')} inputMode="tel" /></Field>
        <Field label="Email" error={f.errors.email}><Input type="email" {...f.bind('email')} /></Field>
        <Field label="Notes" hint="Internal. Only admins see this." className="sm:col-span-2"><Textarea rows={2} {...f.bind('notes')} placeholder="Rates, gear, languages…" /></Field>
        <Field label="Calendar colour" className="sm:col-span-2">
          <div className="flex gap-2">
            {COLORS.map((c) => <button key={c} type="button" onClick={() => f.set('color', c)} className={clsx('h-7 w-7 rounded-full ring-offset-2 ring-offset-surface', f.values.color === c && 'ring-2 ring-ink')} style={{ background: c }} aria-label={c} />)}
          </div>
        </Field>
        {!hasLogin && (
          <div className="rounded-lg border border-line bg-subtle/50 p-3 sm:col-span-2">
            <Checkbox checked={f.values.create_login} onChange={(v) => f.set('create_login', v)} label={<span className="flex items-center gap-1.5"><KeyRound className="h-4 w-4 text-accent-ink" />Let them sign in to the CRM</span>} />
            {f.values.create_login && (
              <div className="mt-3">
                <Field label="Password (optional)" error={f.errors.password} hint="Leave blank to email them a link to set their own password.">
                  <Input type="text" {...f.bind('password')} autoComplete="new-password" />
                </Field>
                <p className="mt-2 text-xs text-muted">They will only see what their role allows: {ROLE_ACCESS[f.values.role] || 'their assigned shoots, calendar and tasks'}.</p>
              </div>
            )}
          </div>
        )}
        {hasLogin && <p className="text-xs text-muted sm:col-span-2">This member can sign in with their email.</p>}
      </div>
    </Modal>
  );
}
