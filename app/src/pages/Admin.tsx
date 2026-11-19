import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, IndianRupee, LogIn, Pencil } from 'lucide-react';
import { api } from '@/lib/api';
import { useMe } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { fmtDate, fromNow, inr } from '@/lib/format';
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Field, Input, Modal, PageHeader, SearchInput, Select, StatCard, TableSkeleton, useUi } from '@/components/ui';

/**
 * Platform admin (WordPress administrators): every studio on this site, plans and status.
 * `standalone` is used when the admin has no studio of their own.
 */
export default function Admin({ standalone }: { standalone?: boolean }) {
  const me = useMe();
  const { toast } = useUi();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const dq = useDebounce(search, 300);
  const [edit, setEdit] = useState<any>(null);
  const q = useQuery({ queryKey: ['admin-studios', dq], queryFn: () => api.get('admin/studios', { q: dq }), enabled: !!me.data?.is_super });
  const plans = me.data?.config.plans || {};

  if (me.data && !me.data.is_super) return <EmptyState title="Platform admin only" description="This page is for the site administrator." />;

  const join = async (s: any) => {
    try {
      await api.post(`admin/studios/${s.id}/join`, {});
      toast(`You joined ${s.name} as support admin`);
      await qc.invalidateQueries();
      window.location.href = window.PCRM.base + '/';
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };

  const st = q.data?.stats;
  return (
    <div className={standalone ? 'mx-auto max-w-6xl p-4 sm:p-8' : ''}>
      <PageHeader title="Platform admin" subtitle="All studios using the CRM on this WordPress site." />
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Studios" value={st?.studios ?? '…'} icon={<Building2 className="h-4 w-4" />} tone="indigo" />
        <StatCard label="Active" value={st?.active ?? '…'} tone="green" />
        <StatCard label="Paid plans" value={st ? Object.entries(st.plans).filter(([k]) => k !== 'free').reduce((s, [, n]) => s + Number(n), 0) : '…'} tone="violet" />
        <StatCard label="Monthly recurring (list price)" value={st ? inr(st.mrr) : '…'} icon={<IndianRupee className="h-4 w-4" />} tone="amber" />
      </div>
      <Card>
        <div className="border-b border-line p-3"><SearchInput value={search} onChange={setSearch} placeholder="Studio, email, city…" className="w-full sm:w-72" /></div>
        {q.error ? (
          <ErrorState error={q.error} onRetry={() => q.refetch()} />
        ) : q.isLoading ? (
          <TableSkeleton />
        ) : !q.data?.studios.length ? (
          <EmptyState title="No studios yet" description="Studios appear here when photographers register." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Studio</th><th className="th">Owner</th><th className="th">Plan</th><th className="th text-right">Team</th><th className="th text-right">Leads</th><th className="th text-right">Bookings</th><th className="th">Last active</th><th className="th">Status</th><th className="th" /></tr></thead>
              <tbody className="divide-y divide-line">
                {q.data.studios.map((s: any) => (
                  <tr key={s.id}>
                    <td className="td"><p className="font-medium">{s.name}</p><p className="text-xs text-muted">{s.city || '—'} · since {fmtDate(s.created_at)}</p></td>
                    <td className="td text-sm">{s.owner_name}<p className="text-xs text-muted">{s.owner_email}</p></td>
                    <td className="td text-sm">{plans[s.plan]?.label || s.plan}{s.plan_expires_at && <p className="text-xs text-muted">until {fmtDate(s.plan_expires_at)}</p>}</td>
                    <td className="td tabular text-right">{s.members}</td>
                    <td className="td tabular text-right">{s.leads}</td>
                    <td className="td tabular text-right">{s.bookings}</td>
                    <td className="td whitespace-nowrap text-sm text-muted">{s.last_active ? fromNow(s.last_active) : '—'}</td>
                    <td className="td"><Badge tone={s.status === 'active' ? 'green' : 'red'}>{s.status === 'active' ? 'Active' : 'Suspended'}</Badge></td>
                    <td className="td whitespace-nowrap text-right">
                      <div className="flex justify-end gap-1.5">
                        <Button size="xs" icon={<Pencil className="h-3.5 w-3.5" />} onClick={() => setEdit(s)}>Plan</Button>
                        <Button size="xs" variant="ghost" icon={<LogIn className="h-3.5 w-3.5" />} onClick={() => join(s)} title="Join as support admin">Open</Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <GoogleSettings />
      <PlanModal studio={edit} plans={plans} onClose={() => setEdit(null)} />
    </div>
  );
}

function PlanModal({ studio, plans, onClose }: { studio: any; plans: Record<string, any>; onClose: () => void }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [v, setV] = useState<{ plan: string; plan_expires_at: string; status: string } | null>(null);
  const [busy, setBusy] = useState(false);
  if (studio && (!v || (v as any)._id !== studio.id)) {
    const nv: any = { plan: studio.plan, plan_expires_at: studio.plan_expires_at || '', status: studio.status, _id: studio.id };
    setV(nv);
  }
  const save = async () => {
    if (!v) return;
    setBusy(true);
    try {
      await api.put(`admin/studios/${studio.id}`, { plan: v.plan, plan_expires_at: v.plan_expires_at || null, status: v.status });
      toast('Studio updated');
      qc.invalidateQueries({ queryKey: ['admin-studios'] });
      qc.invalidateQueries({ queryKey: ['me'] });
      onClose();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal open={!!studio} onClose={onClose} title={`Plan for ${studio?.name || ''}`} size="sm" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
      {v && (
        <div className="space-y-4">
          <Field label="Plan"><Select value={v.plan} onChange={(e) => setV({ ...v, plan: e.target.value })} options={Object.fromEntries(Object.entries(plans).map(([k, p]: any) => [k, `${p.label}${p.price ? ' · ' + inr(p.price) + '/mo' : ''}`]))} /></Field>
          <Field label="Valid until" hint="Leave blank for no expiry. After expiry the studio drops to Free limits."><Input type="date" value={v.plan_expires_at} onChange={(e) => setV({ ...v, plan_expires_at: e.target.value })} /></Field>
          <Field label="Status" hint="Suspended studios are blocked from using the CRM until reactivated."><Select value={v.status} onChange={(e) => setV({ ...v, status: e.target.value })} options={{ active: 'Active', suspended: 'Suspended' }} /></Field>
        </div>
      )}
    </Modal>
  );
}

function Copy({ text }: { text: string }) {
  const { toast } = useUi();
  return (
    <div className="flex items-center gap-2">
      <code className="min-w-0 flex-1 truncate rounded-md bg-subtle px-2 py-1.5 text-xs">{text}</code>
      <Button size="xs" onClick={() => navigator.clipboard.writeText(text).then(() => toast('Copied'))}>Copy</Button>
    </div>
  );
}

/** Platform-wide Google sign-in credentials (Google Cloud OAuth client). */
function GoogleSettings() {
  const { toast } = useUi();
  const q = useQuery({ queryKey: ['admin-settings'], queryFn: () => api.get<any>('admin/settings') });
  const [v, setV] = useState<{ id: string; secret: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  if (q.data && !v) setV({ id: q.data.google_client_id || '', secret: '' });
  const save = async (body: any) => {
    setBusy(true);
    setErr('');
    try {
      const r = await api.post<any>('admin/settings', body);
      q.refetch();
      setV({ id: r.google_client_id || '', secret: '' });
      toast(r.google_enabled ? 'Google sign-in is on' : 'Saved');
    } catch (e) {
      setErr((e as Error).message);
    }
    setBusy(false);
  };
  return (
    <Card className="mt-5">
      <CardHeader
        title="Google sign-in"
        subtitle="Lets studios and team members sign in with their Google account."
        actions={q.data && <Badge tone={q.data.google_enabled ? 'green' : 'gray'}>{q.data.google_enabled ? 'On' : 'Off'}</Badge>}
      />
      {q.data && v && (
        <div className="grid gap-5 p-5 lg:grid-cols-2">
          <ol className="list-decimal space-y-1.5 pl-5 text-sm text-muted">
            <li>Open <a className="text-accent-ink" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noreferrer">Google Cloud → Credentials</a>.</li>
            <li>Set up the OAuth consent screen (External, app name ShootPilot) and publish it.</li>
            <li>Create credentials → OAuth client ID → Web application.</li>
            <li>Authorised JavaScript origin: <Copy text={q.data.google_origin} /></li>
            <li>Authorised redirect URI: <Copy text={q.data.google_redirect} /></li>
            <li>Paste the Client ID and Client secret here and save.</li>
          </ol>
          <div className="space-y-4">
            <Field label="Client ID" error={err}><Input value={v.id} onChange={(e) => setV({ ...v, id: e.target.value })} placeholder="1234-abc.apps.googleusercontent.com" autoComplete="off" /></Field>
            <Field label="Client secret" hint={q.data.google_secret_set ? 'Saved. Leave blank to keep it.' : undefined}><Input type="password" value={v.secret} onChange={(e) => setV({ ...v, secret: e.target.value })} autoComplete="off" /></Field>
            <div className="flex gap-2">
              <Button variant="primary" loading={busy} onClick={() => save({ google_client_id: v.id.trim(), google_client_secret: v.secret.trim() })}>Save</Button>
              {q.data.google_enabled && <Button onClick={() => save({ google_remove: true })}>Turn off</Button>}
            </div>
          </div>
        </div>
      )}
    </Card>
  );
}
