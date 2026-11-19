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
        <StatCard label="Paying (subscription)" value={st?.paid ?? '…'} tone="violet" />
        <StatCard label="Monthly recurring" value={st ? inr(st.mrr) : '…'} sub="Yearly plans counted per month" icon={<IndianRupee className="h-4 w-4" />} tone="amber" />
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
                    <td className="td text-sm">{plans[s.plan]?.label || s.plan}{s.billing_cycle && s.billing_status ? <span className="text-xs text-muted"> · {s.billing_cycle}</span> : null}<p className="text-xs text-muted">{s.billing?.state === 'trial' ? `trial, ${s.billing.days_left}d left` : s.billing?.state === 'trial_ended' ? 'trial ended' : s.billing?.state === 'lapsed' ? `ended ${fmtDate(s.billing.ends_at)}` : s.plan_expires_at ? `until ${fmtDate(s.plan_expires_at)}` : ''}{s.billing_status && s.billing_status !== 'active' ? ` · ${s.billing_status}` : ''}</p></td>
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
      <BillingSettings />
      <PlansEditor />
      <GoogleSettings />
      <PlanModal studio={edit} plans={plans} onClose={() => setEdit(null)} />
    </div>
  );
}

function PlanModal({ studio, plans, onClose }: { studio: any; plans: Record<string, any>; onClose: () => void }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [v, setV] = useState<{ plan: string; plan_expires_at: string; trial_ends_at: string; status: string } | null>(null);
  const [busy, setBusy] = useState(false);
  if (studio && (!v || (v as any)._id !== studio.id)) {
    const nv: any = { plan: studio.plan, plan_expires_at: studio.plan_expires_at || '', trial_ends_at: studio.trial_ends_at || '', status: studio.status, _id: studio.id };
    setV(nv);
  }
  const save = async () => {
    if (!v) return;
    setBusy(true);
    try {
      await api.put(`admin/studios/${studio.id}`, { plan: v.plan, plan_expires_at: v.plan_expires_at || null, trial_ends_at: v.trial_ends_at || null, status: v.status });
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
          <Field label="Plan"><Select value={v.plan} onChange={(e) => setV({ ...v, plan: e.target.value })} options={Object.fromEntries(Object.entries(plans).map(([k, p]: any) => [k, `${p.label}${p.price ? ' · ' + inr(p.price) + '/mo' : ' · trial'}`]))} /></Field>
          {plans[v.plan]?.paid ? (
            <Field label="Paid until" hint="Set automatically by Razorpay subscriptions. Leave blank for no expiry (free of charge, set by you)."><Input type="date" value={v.plan_expires_at} onChange={(e) => setV({ ...v, plan_expires_at: e.target.value })} /></Field>
          ) : (
            <Field label="Trial ends on" hint="Last day of the free trial. Leave blank for no end."><Input type="date" value={v.trial_ends_at} onChange={(e) => setV({ ...v, trial_ends_at: e.target.value })} /></Field>
          )}
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
            {q.data.gcal_redirect && <li>For Google Calendar sync, add a second redirect URI: <Copy text={q.data.gcal_redirect} /> and enable the <b>Google Calendar API</b> in the same Google Cloud project. Studios then connect their own calendar in Settings → Google Calendar.</li>}
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

/** ShootPilot's own Razorpay account for studio subscriptions (not the studios' client-payment keys). */
function BillingSettings() {
  const { toast } = useUi();
  const q = useQuery({ queryKey: ['admin-billing'], queryFn: () => api.get<any>('admin/billing') });
  const [v, setV] = useState<{ key_id: string; key_secret: string; webhook_secret: string; after_expiry: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<Record<string, string>>({});
  if (q.data && !v) setV({ key_id: q.data.key_id || '', key_secret: '', webhook_secret: '', after_expiry: q.data.after_expiry });
  const save = async (body: any) => {
    setBusy(true);
    setErr({});
    try {
      const r = await api.post<any>('admin/billing', body);
      await q.refetch();
      setV({ key_id: r.key_id || '', key_secret: '', webhook_secret: '', after_expiry: r.after_expiry });
      toast('Subscription billing saved');
    } catch (e: any) {
      setErr({ ...(e.errors || {}), _: e.message });
    }
    setBusy(false);
  };
  const d = q.data;
  return (
    <Card className="mt-5">
      <CardHeader
        title="Subscription billing (Razorpay)"
        subtitle="ShootPilot's own Razorpay account, which collects the studios' plan payments. Studios' client-payment keys in their Settings → Online payments are separate."
        actions={d && <Badge tone={d.configured ? (d.mode === 'live' ? 'green' : 'amber') : 'gray'}>{d.configured ? (d.mode === 'live' ? 'Live' : 'Test mode') : 'Not set up'}</Badge>}
      />
      {d && v && (
        <div className="grid gap-5 p-5 lg:grid-cols-2">
          <ol className="list-decimal space-y-1.5 pl-5 text-sm text-muted">
            <li>In the Razorpay Dashboard, switch to <b>Test Mode</b> first, then Account & Settings → API Keys → Generate key.</li>
            <li>Paste the Key ID and Key Secret here. Razorpay plans are created automatically on the first checkout.</li>
            <li>Account & Settings → Webhooks → Add new webhook. URL: <Copy text={d.webhook_url} /></li>
            <li>Use the same webhook secret as below. Events: <b>subscription.authenticated, activated, charged, pending, halted, cancelled, completed, paused, resumed</b>.</li>
            <li>Test an upgrade with Razorpay's test card or UPI <code>success@razorpay</code>. When it works, repeat with live keys (rzp_live_…).</li>
          </ol>
          <div className="space-y-4">
            {d.from_constants && <p className="rounded-lg bg-subtle px-3 py-2 text-sm text-muted">Keys are set in wp-config.php (PCRM_BILLING_KEY_ID / PCRM_BILLING_KEY_SECRET), so they can't be changed here.</p>}
            <Field label="Key ID" error={err.key_id}><Input value={v.key_id} disabled={d.from_constants} onChange={(e) => setV({ ...v, key_id: e.target.value })} placeholder="rzp_test_…" autoComplete="off" /></Field>
            <Field label="Key secret" hint={d.secret_set ? 'Saved. Leave blank to keep it.' : undefined}><Input type="password" disabled={d.from_constants} value={v.key_secret} onChange={(e) => setV({ ...v, key_secret: e.target.value })} autoComplete="off" /></Field>
            <Field label="Webhook secret" error={err.webhook_secret} hint={d.webhook_set ? 'Saved. Leave blank to keep it.' : 'Any strong password, at least 12 characters. Use the same one in Razorpay.'}><Input type="password" value={v.webhook_secret} onChange={(e) => setV({ ...v, webhook_secret: e.target.value })} autoComplete="off" /></Field>
            <Field label="When a trial or paid plan ends">
              <Select value={v.after_expiry} onChange={(e) => setV({ ...v, after_expiry: e.target.value })} options={{ read_only: 'Read-only until they pay (view and export only)', starter_limits: 'Keep working on Starter limits' }} />
            </Field>
            {err._ && <p className="text-sm text-red-600">{err._}</p>}
            <div className="flex gap-2">
              <Button variant="primary" loading={busy} onClick={() => save({ key_id: v.key_id.trim(), key_secret: v.key_secret.trim(), webhook_secret: v.webhook_secret.trim(), after_expiry: v.after_expiry })}>Save</Button>
              {d.configured && !d.from_constants && <Button onClick={() => save({ remove_keys: true })}>Remove keys</Button>}
            </div>
          </div>
        </div>
      )}
    </Card>
  );
}

/** Prices and limits of each plan. -1 = unlimited. */
function PlansEditor() {
  const { toast } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['admin-billing'], queryFn: () => api.get<any>('admin/billing') });
  const [edit, setEdit] = useState<any>(null);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      await api.put(`admin/plans/${edit.key}`, {
        label: edit.label,
        tagline: edit.tagline,
        price: Number(edit.price),
        price_yearly: Number(edit.price_yearly),
        trial_days: Number(edit.trial_days),
        members: Number(edit.members),
        leads_per_month: Number(edit.leads_per_month),
        features: edit.features,
      });
      toast('Plan saved');
      setEdit(null);
      q.refetch();
      qc.invalidateQueries({ queryKey: ['me'] });
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };
  const feats: [string, string][] = [['whatsapp_templates', 'WhatsApp share with ready templates'], ['razorpay_links', 'Razorpay payment links'], ['whatsapp_api', 'WhatsApp Cloud API sending']];
  const lim = (n: number) => (n < 0 ? 'Unlimited' : String(n));
  return (
    <Card className="mt-5">
      <CardHeader title="Plans" subtitle="Shown on the homepage and the Billing page. A price change applies to new subscriptions; running ones keep their price on Razorpay." />
      {q.data && (
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="border-b border-line bg-subtle/50"><tr><th className="th">Plan</th><th className="th text-right">Monthly</th><th className="th text-right">Yearly</th><th className="th text-right">Trial</th><th className="th text-right">Logins</th><th className="th text-right">Leads/mo</th><th className="th">Features</th><th className="th" /></tr></thead>
            <tbody className="divide-y divide-line">
              {Object.values(q.data.plans).map((p: any) => (
                <tr key={p.key}>
                  <td className="td font-medium">{p.label}</td>
                  <td className="td tabular text-right">{p.price ? inr(p.price) : '—'}</td>
                  <td className="td tabular text-right">{p.price_yearly ? inr(p.price_yearly) : '—'}</td>
                  <td className="td tabular text-right">{p.trial_days ? `${p.trial_days} days` : '—'}</td>
                  <td className="td tabular text-right">{lim(p.members)}</td>
                  <td className="td tabular text-right">{lim(p.leads_per_month)}</td>
                  <td className="td text-xs text-muted">{p.features.length ? p.features.map((f: string) => feats.find(([k]) => k === f)?.[1] || f).join(', ') : 'Core modules'}</td>
                  <td className="td text-right"><Button size="xs" icon={<Pencil className="h-3.5 w-3.5" />} onClick={() => setEdit({ ...p })}>Edit</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Modal open={!!edit} onClose={() => setEdit(null)} title={`Edit ${edit?.label || ''}`} size="sm" footer={<><Button onClick={() => setEdit(null)}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
        {edit && (
          <div className="grid grid-cols-2 gap-3">
            <Field label="Name" className="col-span-2"><Input value={edit.label} onChange={(e) => setEdit({ ...edit, label: e.target.value })} /></Field>
            <Field label="Tagline" className="col-span-2"><Input value={edit.tagline} onChange={(e) => setEdit({ ...edit, tagline: e.target.value })} /></Field>
            <Field label="Monthly price (₹)"><Input type="number" min={0} value={edit.price} onChange={(e) => setEdit({ ...edit, price: e.target.value })} /></Field>
            <Field label="Yearly price (₹)"><Input type="number" min={0} value={edit.price_yearly} onChange={(e) => setEdit({ ...edit, price_yearly: e.target.value })} /></Field>
            <Field label="Team logins" hint="-1 = unlimited"><Input type="number" min={-1} value={edit.members} onChange={(e) => setEdit({ ...edit, members: e.target.value })} /></Field>
            <Field label="New leads a month" hint="-1 = unlimited"><Input type="number" min={-1} value={edit.leads_per_month} onChange={(e) => setEdit({ ...edit, leads_per_month: e.target.value })} /></Field>
            <Field label="Free trial days" hint="0 = no trial"><Input type="number" min={0} value={edit.trial_days} onChange={(e) => setEdit({ ...edit, trial_days: e.target.value })} /></Field>
            <div className="col-span-2 space-y-2">
              {feats.map(([k, l]) => (
                <label key={k} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={edit.features.includes(k)} onChange={(e) => setEdit({ ...edit, features: e.target.checked ? [...edit.features, k] : edit.features.filter((x: string) => x !== k) })} />
                  {l}
                </label>
              ))}
            </div>
          </div>
        )}
      </Modal>
    </Card>
  );
}
