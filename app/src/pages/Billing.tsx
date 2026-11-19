import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Check, CreditCard, ExternalLink, Sparkles, Users, UserRound } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fmtDate, inr } from '@/lib/format';
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Modal, PageHeader, PageLoader, Segmented, useUi } from '@/components/ui';

type Cycle = 'monthly' | 'yearly';

declare global {
  interface Window {
    Razorpay?: any;
  }
}

/** Features listed on each plan card. Starter lists the core modules; paid plans list what they add. */
const STARTER_ITEMS = [
  'Leads, pipeline, calling & follow-ups',
  'Quotations with GST and PDF',
  'Bookings, calendar and tasks',
  'Payments, receipts and expenses',
  'Reports and analytics',
];
const PLAN_ITEMS: Record<string, string[]> = {
  starter: STARTER_ITEMS,
  professional: ['Everything in Starter', 'WhatsApp share with ready templates', 'Razorpay payment links (your own account)'],
  business: ['Everything in Professional', 'Best for multi-team agencies'],
};

const STATE_LABEL: Record<string, [string, string]> = {
  trial: ['Free trial', 'accent'],
  trial_ended: ['Trial ended', 'red'],
  active: ['Active', 'green'],
  past_due: ['Payment due', 'amber'],
  lapsed: ['Expired', 'red'],
  manual: ['Active', 'green'],
};

let checkoutScript: Promise<void> | null = null;
function loadCheckout() {
  if (window.Razorpay) return Promise.resolve();
  if (!checkoutScript) {
    checkoutScript = new Promise<void>((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://checkout.razorpay.com/v1/checkout.js';
      s.async = true;
      s.onload = () => resolve();
      s.onerror = () => {
        checkoutScript = null;
        reject(new Error('Could not load Razorpay Checkout. Check your internet connection or ad blocker, or use the payment page link.'));
      };
      document.body.appendChild(s);
    });
  }
  return checkoutScript;
}

const lim = (n: number, one: string, many: string) => (n < 0 ? `Unlimited ${many}` : `${n} ${n === 1 ? one : many}`);

export default function Billing() {
  const { can, refresh } = useAuth();
  const { toast } = useUi();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['billing'], queryFn: () => api.get<any>('billing'), enabled: can('settings.edit') });
  const [cycle, setCycle] = useState<Cycle>('yearly');
  const [busy, setBusy] = useState('');
  const [fallback, setFallback] = useState<{ url: string; label: string } | null>(null);
  const [confirmCancel, setConfirmCancel] = useState(false);

  if (!can('settings.edit')) return <EmptyState title="Owners and admins only" description="Ask your studio owner to manage the plan." />;
  if (q.error) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  if (q.isLoading || !q.data) return <PageLoader />;

  const d = q.data;
  const st = d.status;
  const plans = Object.values(d.plans) as any[];
  const sub = d.subscription;
  const [stLabel, stTone] = STATE_LABEL[st.state] || ['Active', 'green'];
  const renewing = sub && ['active', 'authenticated'].includes(sub.status) && !sub.cancel_requested_at && st.plan === sub.plan;

  const done = async () => {
    await qc.invalidateQueries({ queryKey: ['billing'] });
    refresh();
  };

  const upgrade = async (plan: string) => {
    setBusy(plan);
    setFallback(null);
    try {
      const r = await api.post<any>('billing/checkout', { plan, cycle });
      try {
        await loadCheckout();
      } catch (e) {
        if (r.short_url) setFallback({ url: r.short_url, label: r.description });
        throw e;
      }
      await new Promise<void>((resolve) => {
        const rz = new window.Razorpay({
          key: r.key_id,
          subscription_id: r.subscription_id,
          name: r.name,
          description: r.description,
          prefill: r.prefill,
          theme: { color: '#F3724C' },
          notes: { plan, cycle },
          handler: async (resp: any) => {
            try {
              await api.post('billing/verify', resp);
              toast('Payment received. Your plan is active.');
              await done();
            } catch (e: any) {
              toast(e.message, 'error');
            }
            resolve();
          },
          modal: { ondismiss: () => resolve() },
        });
        rz.on('payment.failed', (resp: any) => {
          toast(resp?.error?.description || 'Payment failed. No money was taken. Please try again.', 'error');
        });
        rz.open();
      });
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
    q.refetch();
  };

  const cancel = async () => {
    setBusy('cancel');
    try {
      await api.post('billing/cancel', {});
      toast('Auto-renew is off. Your plan stays active until the end of the paid period.');
      setConfirmCancel(false);
      await done();
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy('');
  };

  const meter = (icon: React.ReactNode, label: string, used: number, limit: number, sub2?: string) => {
    const pct = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : limit === 0 ? 100 : 0;
    const full = limit >= 0 && used >= limit;
    return (
      <div className="rounded-xl border border-line p-4">
        <div className="flex items-center gap-2 text-sm font-medium text-muted [&>svg]:h-4 [&>svg]:w-4">{icon}{label}</div>
        <p className="tabular mt-1 text-xl font-semibold">
          {used}
          <span className="text-sm font-normal text-muted"> / {limit < 0 ? 'Unlimited' : limit}</span>
        </p>
        {limit >= 0 && (
          <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-subtle">
            <div className={clsx('h-full rounded-full', full ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500')} style={{ width: `${pct}%` }} />
          </div>
        )}
        {sub2 && <p className="mt-1.5 text-xs text-muted">{sub2}</p>}
        {full && <p className="mt-1 text-xs font-medium text-red-600 dark:text-red-400">Limit reached. Upgrade to add more.</p>}
      </div>
    );
  };

  return (
    <div>
      <PageHeader title="Billing & plan" subtitle="Your ShootPilot subscription, usage and invoices. All prices in INR." />

      {d.checkout.mode === 'test' && (
        <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
          <b>Test mode.</b> Payments use Razorpay test keys, so no real money is charged.
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader title="Current plan" actions={<Badge tone={stTone}>{stLabel}</Badge>} />
          <div className="space-y-2 p-5 pt-0">
            <p className="text-2xl font-semibold">{st.label}</p>
            {st.state === 'trial' && (
              <p className="text-sm">
                <b>{st.days_left} {st.days_left === 1 ? 'day' : 'days'} left</b> in your free trial. It ends on {fmtDate(st.ends_at)}.
              </p>
            )}
            {st.state === 'trial_ended' && <p className="text-sm text-red-600 dark:text-red-400">Your free trial ended on {fmtDate(st.ends_at)}. {st.read_only ? 'Your data is safe and visible, but adding and editing is paused until you choose a plan.' : 'You are on Starter limits.'}</p>}
            {st.state === 'active' && sub && (
              <p className="text-sm text-muted">
                {inr(sub.amount)} / {sub.cycle === 'yearly' ? 'year' : 'month'}. {renewing ? `Renews on ${fmtDate(st.ends_at)}.` : `Active until ${fmtDate(st.ends_at)}. Auto-renew is off.`}
              </p>
            )}
            {st.state === 'active' && !sub && <p className="text-sm text-muted">Active until {fmtDate(st.ends_at)}.</p>}
            {st.state === 'manual' && <p className="text-sm text-muted">Set up by ShootPilot support{st.ends_at ? `, until ${fmtDate(st.ends_at)}` : ''}.</p>}
            {st.state === 'past_due' && <p className="text-sm text-amber-700 dark:text-amber-300">Your renewal payment is pending. Razorpay will retry it. Your plan keeps working for a few days meanwhile.</p>}
            {st.state === 'lapsed' && <p className="text-sm text-red-600 dark:text-red-400">Your plan ended on {fmtDate(st.ends_at)}. {st.read_only ? 'Your data is safe and visible. Renew to keep adding and editing.' : 'You are on Starter limits.'}</p>}
            {renewing && (
              <button className="pt-2 text-xs text-muted underline hover:text-ink" onClick={() => setConfirmCancel(true)}>
                Turn off auto-renew
              </button>
            )}
          </div>
        </Card>
        <div className="grid gap-4 sm:grid-cols-2 lg:col-span-2">
          {meter(<Users />, 'Team logins', d.usage.logins.used, d.usage.logins.limit, 'Crew without a login are unlimited on every plan.')}
          {meter(<UserRound />, 'New leads this month', d.usage.leads.used, d.usage.leads.limit, d.usage.leads.limit >= 0 ? `Resets on ${fmtDate(d.usage.leads.resets_on)}.` : undefined)}
        </div>
      </div>

      <div className="mb-3 mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-base font-semibold">Plans</h2>
        <Segmented value={cycle} onChange={setCycle} options={[['monthly', 'Monthly'], ['yearly', 'Yearly · save up to 33%']]} />
      </div>
      {!d.checkout.enabled && (
        <p className="mb-3 rounded-lg bg-subtle px-4 py-2.5 text-sm text-muted">Online payment for plans is not switched on yet. Contact ShootPilot support to upgrade.</p>
      )}
      {fallback && (
        <p className="mb-3 rounded-lg bg-subtle px-4 py-2.5 text-sm">
          Razorpay Checkout could not open here. <a className="font-medium text-accent-ink underline" href={fallback.url} target="_blank" rel="noreferrer">Pay on Razorpay's page<ExternalLink className="ml-1 inline h-3.5 w-3.5" /></a> ({fallback.label}). Your plan activates automatically once Razorpay confirms the payment.
        </p>
      )}
      <div className="grid gap-4 md:grid-cols-3">
        {plans.map((p) => {
          const current = p.key === st.plan && !['trial_ended', 'lapsed'].includes(st.state);
          const price = cycle === 'yearly' ? p.price_yearly : p.price;
          const save = p.price > 0 && p.price_yearly > 0 ? Math.round((1 - p.price_yearly / (p.price * 12)) * 100) : 0;
          const sameCycle = current && sub && sub.cycle === cycle && renewing;
          return (
            <div key={p.key} className={clsx('relative flex flex-col rounded-xl border bg-surface p-5', p.key === 'professional' ? 'border-accent ring-1 ring-accent' : 'border-line')}>
              {p.key === 'professional' && <span className="absolute -top-2.5 left-5 rounded-full bg-accent px-2 py-0.5 text-2xs font-semibold text-white dark:text-slate-950">Most popular</span>}
              <div className="flex items-center justify-between">
                <p className="font-semibold">{p.label}</p>
                {current && <Badge tone="accent">Current</Badge>}
              </div>
              <p className="mt-0.5 text-xs text-muted">{p.tagline}</p>
              <p className="tabular mt-3 text-3xl font-semibold">
                {p.paid ? inr(price) : 'Free'}
                <span className="text-sm font-normal text-muted">{p.paid ? (cycle === 'yearly' ? ' / year' : ' / month') : ` for ${p.trial_days} days`}</span>
              </p>
              <p className="h-5 text-xs text-muted">{p.paid && cycle === 'yearly' && save > 0 ? `${inr(Math.round(p.price_yearly / 12))}/month, save ${save}%` : p.paid ? `or ${inr(p.price_yearly)} / year` : 'No card needed'}</p>
              <ul className="mt-4 flex-1 space-y-2 text-sm">
                <li className="flex gap-2"><Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />{lim(p.members, 'team login', 'team logins')}</li>
                <li className="flex gap-2"><Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />{lim(p.leads_per_month, 'new lead a month', 'new leads a month').replace('Unlimited new leads a month', 'Unlimited leads')}</li>
                {(PLAN_ITEMS[p.key] || []).map((it) => (
                  <li key={it} className="flex gap-2"><Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />{it}</li>
                ))}
              </ul>
              <div className="mt-5">
                {!p.paid ? (
                  <Button className="w-full" disabled>{st.plan === p.key && st.state === 'trial' ? 'Your current plan' : 'Trial plan'}</Button>
                ) : sameCycle ? (
                  <Button className="w-full" disabled>Your current plan</Button>
                ) : (
                  <Button className="w-full" variant={p.key === 'professional' ? 'primary' : 'secondary'} icon={<Sparkles className="h-4 w-4" />} loading={busy === p.key} disabled={!d.checkout.enabled || !!busy} onClick={() => upgrade(p.key)}>
                    {current ? `Switch to ${cycle}` : st.plan === 'business' && p.key === 'professional' && !['trial_ended', 'lapsed'].includes(st.state) ? 'Switch to Professional' : `Upgrade to ${p.label}`}
                  </Button>
                )}
              </div>
            </div>
          );
        })}
      </div>
      <p className="mt-3 text-xs text-muted">Prices include all features shown. Payments are processed by Razorpay (UPI, cards, net banking). A new plan starts as soon as you pay; any earlier subscription is cancelled automatically.</p>

      <Card className="mt-8">
        <CardHeader title="Billing history" subtitle="Payments for your ShootPilot subscription." />
        {d.history.length === 0 ? (
          <EmptyState icon={<CreditCard className="h-5 w-5" />} title="No payments yet" description="Your subscription payments will appear here." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-line bg-subtle/50">
                <tr><th className="th">Date</th><th className="th">Plan</th><th className="th text-right">Amount</th><th className="th">Covers until</th><th className="th">Payment ID</th></tr>
              </thead>
              <tbody className="divide-y divide-line">
                {d.history.map((h: any) => (
                  <tr key={h.id}>
                    <td className="td whitespace-nowrap">{fmtDate(h.paid_at)}</td>
                    <td className="td">{d.plans[h.plan_key]?.label || h.plan_key} · {h.billing_cycle === 'yearly' ? 'Yearly' : 'Monthly'}</td>
                    <td className="td tabular text-right">{inr(h.amount)}</td>
                    <td className="td whitespace-nowrap">{fmtDate(h.period_end)}</td>
                    <td className="td font-mono text-xs text-muted">{h.gateway_payment_id}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={confirmCancel}
        onClose={() => setConfirmCancel(false)}
        title="Turn off auto-renew?"
        size="sm"
        footer={<><Button onClick={() => setConfirmCancel(false)}>Keep it on</Button><Button variant="danger" loading={busy === 'cancel'} onClick={cancel}>Turn off</Button></>}
      >
        <p className="text-sm">Your {st.label} plan stays active until {fmtDate(st.ends_at)}. After that {st.after_expiry === 'read_only' ? 'your studio becomes read-only until you choose a plan again' : 'you move to Starter limits'}. Your data is never deleted.</p>
      </Modal>
    </div>
  );
}
