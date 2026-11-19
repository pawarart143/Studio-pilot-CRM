import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  UsersRound,
  BarChart3, Briefcase, Gem, PackageCheck, CalendarDays, CheckSquare, ChevronsUpDown, Columns3, CreditCard, FileText, Home, LogOut, Menu as MenuIcon, Moon, Phone, Plus, Search, Settings, Shield, Sun, UserRound, Users, Contact, X, Check,
} from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { api } from '@/lib/api';
import { useDebounce, useTheme } from '@/lib/hooks';
import { Avatar, Badge, Button, Logo, Menu, MenuItem, Modal, useUi } from './ui';
import { LeadFormModal } from '@/pages/leads/LeadForm';
import { Reminders } from './Reminders';

type NavItem = { to: string; label: string; icon: ReactNode; perm?: string; badge?: number };

export default function AppLayout({ children }: { children: ReactNode }) {
  const { can, me, studio, member } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [leadOpen, setLeadOpen] = useState(false);
  const loc = useLocation();
  const { dark, toggle } = useTheme();
  useEffect(() => setMobileOpen(false), [loc.pathname]);

  const summary = useQuery({ queryKey: ['calling-summary'], queryFn: () => api.get('calling/summary'), enabled: can('leads.view'), refetchInterval: 120_000 });
  const callBadge = summary.data ? summary.data.today + summary.data.overdue : undefined;

  const groups: { title?: string; items: NavItem[] }[] = [
    { items: [{ to: '/', label: 'Dashboard', icon: <Home />, perm: 'dashboard.view' }] },
    {
      title: 'Sales',
      items: [
        { to: '/leads', label: 'Leads', icon: <UserRound />, perm: 'leads.view' },
        { to: '/pipeline', label: 'Pipeline', icon: <Columns3 />, perm: 'leads.view' },
        { to: '/calling', label: 'Calling & Follow-ups', icon: <Phone />, perm: 'leads.view', badge: callBadge },
        { to: '/quotations', label: 'Quotations', icon: <FileText />, perm: 'quotes.view' },
      ],
    },
    {
      title: 'Operations',
      items: [
        { to: '/bookings', label: 'Bookings & Projects', icon: <Briefcase />, perm: 'bookings.view' },
        { to: '/deliverables', label: 'Deliverables', icon: <PackageCheck />, perm: 'deliverables.view' },
        { to: '/calendar', label: 'Calendar', icon: <CalendarDays />, perm: 'calendar.view' },
        { to: '/tasks', label: 'Tasks', icon: <CheckSquare />, perm: 'tasks.view' },
        { to: '/crew', label: 'Crew Scheduling', icon: <UsersRound />, perm: 'crew.view' },
        { to: '/clients', label: 'Clients', icon: <Contact />, perm: 'clients.view' },
      ],
    },
    {
      title: 'Business',
      items: [
        { to: '/payments', label: 'Payments & Expenses', icon: <CreditCard />, perm: 'payments.view' },
        { to: '/reports', label: 'Reports', icon: <BarChart3 />, perm: 'reports.view' },
        { to: '/team', label: 'Team', icon: <Users />, perm: 'team.view' },
      ],
    },
  ];

  const sidebar = (
    <div className="flex h-full flex-col">
      <div className="flex h-14 items-center gap-2.5 px-4">
        <Logo className="h-8 w-8 shrink-0" />
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold leading-tight">ShootPilot</p>
          <p className="truncate text-2xs text-muted">{studio.name}</p>
        </div>
      </div>
      <nav className="scrollbar-thin flex-1 space-y-5 overflow-y-auto px-3 py-3">
        {groups.map((g, i) => {
          const items = g.items.filter((it) => !it.perm || can(it.perm));
          if (!items.length) return null;
          return (
            <div key={i}>
              {g.title && <p className="mb-1 px-2.5 text-2xs font-semibold uppercase tracking-wider text-faint">{g.title}</p>}
              <div className="space-y-0.5">
                {items.map((it) => (
                  <NavLink
                    key={it.to}
                    to={it.to}
                    end={it.to === '/'}
                    className={({ isActive }) =>
                      clsx(
                        'group flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors [&>svg]:h-4 [&>svg]:w-4',
                        isActive ? 'bg-accent-soft text-accent-ink' : 'text-muted hover:bg-subtle hover:text-ink',
                      )
                    }
                  >
                    {it.icon}
                    <span className="flex-1 truncate">{it.label}</span>
                    {!!it.badge && <span className="rounded-full bg-red-500 px-1.5 text-2xs font-semibold text-white">{it.badge}</span>}
                  </NavLink>
                ))}
              </div>
            </div>
          );
        })}
      </nav>
      <div className="space-y-0.5 border-t border-line px-3 py-3">
        {can('settings.edit') && (
          <NavLink to="/settings" className={({ isActive }) => clsx('flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium [&>svg]:h-4 [&>svg]:w-4', isActive ? 'bg-accent-soft text-accent-ink' : 'text-muted hover:bg-subtle hover:text-ink')}>
            <Settings /> Settings
          </NavLink>
        )}
        {can('settings.edit') && (
          <NavLink to="/billing" className={({ isActive }) => clsx('flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium [&>svg]:h-4 [&>svg]:w-4', isActive ? 'bg-accent-soft text-accent-ink' : 'text-muted hover:bg-subtle hover:text-ink')}>
            <Gem /> Billing & plan
          </NavLink>
        )}
        {me.is_super && (
          <NavLink to="/admin" className={({ isActive }) => clsx('flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium [&>svg]:h-4 [&>svg]:w-4', isActive ? 'bg-accent-soft text-accent-ink' : 'text-muted hover:bg-subtle hover:text-ink')}>
            <Shield /> Super Admin
          </NavLink>
        )}
        {can('settings.edit') && <PlanBox />}
      </div>
    </div>
  );

  const bottom = [
    { to: '/', label: 'Home', icon: <Home />, perm: 'dashboard.view' },
    { to: '/leads', label: 'Leads', icon: <UserRound />, perm: 'leads.view' },
    { to: '/calling', label: 'Calls', icon: <Phone />, perm: 'leads.view' },
    { to: '/calendar', label: 'Calendar', icon: <CalendarDays />, perm: 'calendar.view' },
    // Crew without sales access get their deliverables in the bottom bar.
    ...(!can('leads.view') ? [{ to: '/deliverables', label: 'Deliverables', icon: <PackageCheck />, perm: 'deliverables.view' }] : []),
  ].filter((b) => can(b.perm));

  return (
    <div className="min-h-screen">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 border-r border-line bg-surface lg:block">{sidebar}</aside>
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-slate-950/40" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72 border-r border-line bg-surface shadow-pop">
            <button className="absolute right-3 top-4 rounded-lg p-1 text-muted" onClick={() => setMobileOpen(false)} aria-label="Close menu">
              <X className="h-5 w-5" />
            </button>
            {sidebar}
          </aside>
        </div>
      )}
      <div className="lg:pl-60">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-line bg-surface/85 px-3 backdrop-blur sm:px-5">
          <button className="rounded-lg p-2 text-muted hover:bg-subtle lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open menu">
            <MenuIcon className="h-5 w-5" />
          </button>
          <GlobalSearch />
          <div className="ml-auto flex items-center gap-1">
            {can('leads.edit') && (
              <button onClick={() => setLeadOpen(true)} className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-accent px-3 text-sm font-medium text-white shadow-sm hover:bg-accent/90 dark:text-slate-950">
                <Plus className="h-4 w-4" />
                <span className="hidden sm:inline">New Lead</span>
              </button>
            )}
            <button onClick={toggle} className="rounded-lg p-2 text-muted hover:bg-subtle hover:text-ink" aria-label="Toggle dark mode" title={dark ? 'Light mode' : 'Dark mode'}>
              {dark ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
            </button>
            <UserMenu />
          </div>
        </header>
        <main className="mx-auto max-w-[1400px] px-3 pb-24 pt-5 sm:px-5 lg:px-7 lg:pb-10">
          <PlanBanner />
          {children}
        </main>
      </div>
      <nav className="fixed inset-x-0 bottom-0 z-30 flex border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
        {bottom.map((b) => (
          <NavLink key={b.to} to={b.to} end={b.to === '/'} className={({ isActive }) => clsx('flex flex-1 flex-col items-center gap-0.5 py-2 text-2xs font-medium [&>svg]:h-5 [&>svg]:w-5', isActive ? 'text-accent-ink' : 'text-muted')}>
            {b.icon}
            {b.label}
          </NavLink>
        ))}
        <button onClick={() => setMobileOpen(true)} className="flex flex-1 flex-col items-center gap-0.5 py-2 text-2xs font-medium text-muted">
          <MenuIcon className="h-5 w-5" />
          More
        </button>
      </nav>
      <LeadFormModal open={leadOpen} onClose={() => setLeadOpen(false)} />
      <Reminders />
      <UpgradePrompt />
      <span className="hidden">{member.id}</span>
    </div>
  );
}

function UserMenu() {
  const { me, member, studio, config } = useAuth();
  const qc = useQueryClient();
  const { toast } = useUi();
  const logout = async () => {
    await api.post('auth/logout');
    window.location.href = window.PCRM.base + '/login';
  };
  const switchTo = async (id: number) => {
    try {
      await api.post('me/studio', { studio_id: id });
      qc.clear();
      window.location.href = window.PCRM.base + '/';
    } catch (e: any) {
      toast(e.message, 'error');
    }
  };
  return (
    <Menu
      trigger={() => (
        <button className="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-subtle">
          <Avatar name={me.user?.name} color={member.color} size="sm" />
          <span className="hidden max-w-[120px] truncate text-sm font-medium md:block">{me.user?.name}</span>
          <ChevronsUpDown className="hidden h-3.5 w-3.5 text-faint md:block" />
        </button>
      )}
    >
      {(close) => (
        <div className="w-64">
          <div className="px-2.5 py-2">
            <p className="truncate text-sm font-semibold">{me.user?.name}</p>
            <p className="truncate text-xs text-muted">{me.user?.email}</p>
            <Badge className="mt-1.5" tone="accent">
              {config.roles[member.role]}
            </Badge>
          </div>
          {(me.studios?.length || 0) > 1 && (
            <div className="border-t border-line py-1">
              <p className="px-2.5 py-1 text-2xs font-semibold uppercase tracking-wider text-faint">Switch studio</p>
              {me.studios!.map((s) => (
                <MenuItem key={s.id} onClick={() => (close(), s.id !== studio.id && switchTo(s.id))} icon={s.id === studio.id ? <Check /> : <span className="inline-block h-4 w-4" />}>
                  <span className="truncate">{s.name}</span>
                </MenuItem>
              ))}
            </div>
          )}
          <div className="border-t border-line pt-1">
            <Link to="/settings?tab=profile" onClick={close} className="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm hover:bg-subtle">
              <UserRound className="h-4 w-4 text-muted" /> My profile
            </Link>
            <MenuItem icon={<LogOut />} onClick={logout}>
              Sign out
            </MenuItem>
          </div>
        </div>
      )}
    </Menu>
  );
}

function GlobalSearch() {
  const { can, config } = useAuth();
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const dq = useDebounce(q, 250);
  const nav = useNavigate();
  const ref = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const enabled = dq.trim().length >= 2;
  const leads = useQuery({ queryKey: ['gs-leads', dq], queryFn: () => api.get('leads', { q: dq, per_page: 5 }), enabled: enabled && can('leads.view') });
  const clients = useQuery({ queryKey: ['gs-clients', dq], queryFn: () => api.get('clients', { q: dq, per_page: 4 }), enabled: enabled && can('clients.view') });
  const bookings = useQuery({ queryKey: ['gs-bookings', dq], queryFn: () => api.get('bookings', { q: dq, per_page: 4 }), enabled: enabled && can('bookings.view') });
  useEffect(() => {
    const h = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
    const k = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        inputRef.current?.focus();
        setOpen(true);
      }
    };
    document.addEventListener('mousedown', h);
    window.addEventListener('keydown', k);
    return () => {
      document.removeEventListener('mousedown', h);
      window.removeEventListener('keydown', k);
    };
  }, []);
  const go = (path: string) => {
    setOpen(false);
    setQ('');
    nav(path);
  };
  const results = [
    ...(leads.data?.items || []).map((l: any) => ({ key: 'l' + l.id, path: `/leads/${l.id}`, title: l.name, sub: `Lead · ${config.stages[l.stage]} · ${l.phone || ''}` })),
    ...(clients.data?.items || []).map((c: any) => ({ key: 'c' + c.id, path: `/clients/${c.id}`, title: c.name, sub: `Client · ${c.phone || ''}` })),
    ...(bookings.data?.items || []).map((b: any) => ({ key: 'b' + b.id, path: `/bookings/${b.id}`, title: b.title, sub: `Booking · ${b.number}` })),
  ];
  const loading = leads.isFetching || clients.isFetching || bookings.isFetching;
  return (
    <div ref={ref} className="relative w-full max-w-md">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
      <input
        ref={inputRef}
        value={q}
        onFocus={() => setOpen(true)}
        onChange={(e) => (setQ(e.target.value), setOpen(true))}
        onKeyDown={(e) => e.key === 'Enter' && results[0] && go(results[0].path)}
        placeholder="Search leads, clients, bookings…"
        className="h-9 w-full rounded-lg border border-transparent bg-subtle pl-9 pr-12 text-sm placeholder:text-faint focus:border-line focus:bg-surface focus:outline-none"
      />
      <kbd className="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-line px-1.5 text-2xs text-faint sm:block">⌘K</kbd>
      {open && enabled && (
        <div className="absolute left-0 right-0 top-11 z-40 overflow-hidden rounded-xl border border-line bg-surface shadow-pop">
          {results.length === 0 ? (
            <p className="px-4 py-6 text-center text-sm text-muted">{loading ? 'Searching…' : 'No matches found'}</p>
          ) : (
            <ul className="max-h-96 overflow-y-auto py-1">
              {results.map((r) => (
                <li key={r.key}>
                  <button onClick={() => go(r.path)} className="flex w-full flex-col px-4 py-2 text-left hover:bg-subtle">
                    <span className="truncate text-sm font-medium">{r.title}</span>
                    <span className="truncate text-xs text-muted">{r.sub}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

/** Plan summary at the bottom of the sidebar. */
function PlanBox() {
  const { studio, can } = useAuth();
  const b = studio.billing;
  const label = b?.label || studio.plan_info.label;
  const members = b ? b.members : studio.plan_info.members;
  const leads = b ? b.leads_per_month : studio.plan_info.leads_per_month;
  const body = (
    <>
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium">{label} plan</span>
        {b?.state === 'trial' && <Badge tone="accent">{b.days_left}d left</Badge>}
        {(b?.state === 'trial_ended' || b?.state === 'lapsed') && <Badge tone="red">Upgrade</Badge>}
      </div>
      <p className="mt-0.5 text-2xs text-muted">{members < 0 ? 'Unlimited' : members} team {members === 1 ? 'login' : 'logins'} · {leads < 0 ? 'unlimited' : leads} leads/mo</p>
    </>
  );
  return can('settings.edit') ? (
    <Link to="/billing" className="mt-2 block rounded-lg bg-subtle px-3 py-2.5 hover:bg-line/60">{body}</Link>
  ) : (
    <div className="mt-2 rounded-lg bg-subtle px-3 py-2.5">{body}</div>
  );
}

/** Banner for a trial that is ending or a plan that has ended. */
function PlanBanner() {
  const { studio, can } = useAuth();
  const loc = useLocation();
  const b = studio.billing;
  if (!b || loc.pathname === '/billing') return null;
  const owner = can('settings.edit');
  let tone = '';
  let text = '';
  if (b.state === 'trial' && b.days_left !== null && b.days_left <= 5) {
    tone = 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200';
    text = `Your free trial ends in ${b.days_left} ${b.days_left === 1 ? 'day' : 'days'}.`;
  } else if (b.state === 'trial_ended' || b.state === 'lapsed') {
    tone = 'border-red-300 bg-red-50 text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200';
    text = (b.state === 'trial_ended' ? 'Your free trial has ended.' : `Your ${b.label} plan has ended.`) + (b.read_only ? ' Your data is safe and you can view it, but adding and editing is paused.' : ' You are on Starter limits.');
  } else if (b.state === 'past_due') {
    tone = 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200';
    text = 'Your renewal payment is pending. Razorpay will retry it.';
  }
  if (!text) return null;
  return (
    <div className={clsx('mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm', tone)}>
      <span>{text}{!owner && ' Ask your studio owner to choose a plan.'}</span>
      {owner && <Link to="/billing" className="font-semibold underline">See plans</Link>}
    </div>
  );
}

/** Opens when the server answers 402 (plan limit, plan feature or ended trial). */
function UpgradePrompt() {
  const { can, refresh } = useAuth();
  const navigate = useNavigate();
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    const on = (e: Event) => {
      const d = (e as CustomEvent).detail || {};
      setMsg(d.message || 'Your plan does not include this.');
      if (d.code === 'plan_expired') refresh();
    };
    window.addEventListener('pcrm:upgrade', on);
    return () => window.removeEventListener('pcrm:upgrade', on);
  }, [refresh]);
  return (
    <Modal
      open={!!msg}
      onClose={() => setMsg(null)}
      title="Upgrade your plan"
      size="sm"
      footer={
        <>
          <Button onClick={() => setMsg(null)}>Close</Button>
          {can('settings.edit') && (
            <Button variant="primary" onClick={() => { setMsg(null); navigate('/billing'); }}>See plans</Button>
          )}
        </>
      }
    >
      <p className="text-sm">{msg}</p>
      {!can('settings.edit') && <p className="mt-2 text-sm text-muted">Ask your studio owner or admin to upgrade.</p>}
    </Modal>
  );
}
