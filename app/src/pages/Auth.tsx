import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Eye, EyeOff } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import type { Me } from '@/lib/auth';
import { Button, Field, Input, Logo } from '@/components/ui';

function Shell({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="flex min-h-screen">
      <div className="relative hidden w-[46%] overflow-hidden bg-slate-950 lg:block">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,rgba(243,114,76,0.45),transparent_55%),radial-gradient(ellipse_at_bottom_right,rgba(251,191,36,0.18),transparent_50%)]" />
        <div className="relative flex h-full flex-col justify-between p-12 text-white">
          <div className="flex items-center gap-2.5">
            <Logo className="h-9 w-9" />
            <span className="text-lg font-semibold">ShootPilot</span>
          </div>
          <div>
            <h2 className="max-w-md text-3xl font-semibold leading-tight">Run your entire photography business from one place.</h2>
            <ul className="mt-6 space-y-3 text-sm text-white/75">
              {['Enquiries, calls and follow-ups that never slip', 'Beautiful quotations with GST, shared on WhatsApp', 'Bookings, team assignments and no double-booking', 'Payments, receipts and business reports in ₹'].map((t) => (
                <li key={t} className="flex items-center gap-2.5">
                  <CheckCircle2 className="h-4 w-4 text-[#F3724C]" />
                  {t}
                </li>
              ))}
            </ul>
          </div>
          <p className="text-xs text-white/40">Made for wedding, event and commercial photographers in India.</p>
        </div>
      </div>
      <div className="flex flex-1 items-center justify-center px-5 py-10">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-2 lg:hidden">
            <Logo className="h-8 w-8" />
            <span className="font-semibold">ShootPilot</span>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {subtitle && <p className="mt-1.5 text-sm text-muted">{subtitle}</p>}
          <div className="mt-7">{children}</div>
          {footer && <div className="mt-6 text-center text-sm text-muted">{footer}</div>}
        </div>
      </div>
    </div>
  );
}

function Password({ value, onChange, error, autoComplete = 'current-password', id }: { value: string; onChange: (v: string) => void; error?: string; autoComplete?: string; id?: string }) {
  const [show, setShow] = useState(false);
  return (
    <div className="relative">
      <Input id={id} type={show ? 'text' : 'password'} value={value} onChange={(e) => onChange(e.target.value)} invalid={!!error} autoComplete={autoComplete} className="pr-10" required />
      <button type="button" onClick={() => setShow(!show)} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-faint hover:text-ink" aria-label={show ? 'Hide password' : 'Show password'}>
        {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  );
}

function Alert({ children, tone = 'error' }: { children: ReactNode; tone?: 'error' | 'ok' }) {
  return <div className={tone === 'error' ? 'mb-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'mb-4 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'}>{children}</div>;
}

const GOOGLE_ERRORS: Record<string, string> = {
  cancelled: 'Google sign-in was cancelled.',
  state: 'Google sign-in expired. Please try again.',
  token: 'Google could not confirm your account. Please try again.',
  unverified: 'Your Google email is not verified.',
  not_configured: 'Google sign-in is not set up on this site yet.',
  create: 'Could not create your account. Please try again.',
};

function GoogleButton({ label }: { label: string }) {
  const url = window.PCRM.google;
  if (!url) return null;
  return (
    <>
      <a href={url} className="flex h-10 w-full items-center justify-center gap-2.5 rounded-lg border border-line bg-surface text-sm font-medium text-ink shadow-sm hover:bg-subtle">
        <svg viewBox="0 0 48 48" className="h-4 w-4" aria-hidden="true">
          <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
          <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
          <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
          <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
        </svg>
        {label}
      </a>
      <div className="my-5 flex items-center gap-3 text-xs text-faint">
        <span className="h-px flex-1 bg-line" />or<span className="h-px flex-1 bg-line" />
      </div>
    </>
  );
}

export function Login() {
  const [sp] = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await api.post('auth/login', { email, password, remember });
      const next = sp.get('next');
      window.location.href = next && next.startsWith(window.PCRM.base) ? next : window.PCRM.base + '/';
    } catch (err) {
      setError((err as Error).message);
      setLoading(false);
    }
  };
  return (
    <Shell title="Welcome back" subtitle="Sign in to your studio workspace." footer={<>New to ShootPilot? <Link to="/register" className="font-medium text-accent-ink">Create your studio</Link></>}>
      {sp.get('reset') && <Alert tone="ok">Password updated. Please sign in with your new password.</Alert>}
      {sp.get('google_error') && <Alert>{GOOGLE_ERRORS[sp.get('google_error') as string] || 'Google sign-in failed. Please try again.'}</Alert>}
      {error && <Alert>{error}</Alert>}
      <GoogleButton label="Continue with Google" />
      <form onSubmit={submit} className="space-y-4">
        <Field label="Email">
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" required autoFocus />
        </Field>
        <Field
          label={
            <span className="flex justify-between">
              Password
              <Link to="/forgot" className="font-medium text-accent-ink">
                Forgot password?
              </Link>
            </span>
          }
        >
          <Password value={password} onChange={setPassword} />
        </Field>
        <label className="flex items-center gap-2 text-sm text-muted">
          <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="h-4 w-4 rounded" /> Keep me signed in
        </label>
        <Button type="submit" variant="primary" className="w-full" loading={loading}>
          Sign in
        </Button>
      </form>
    </Shell>
  );
}

export function Register() {
  const [f, setF] = useState({ name: '', studio_name: '', email: '', phone: '', city: '', password: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (f.password.length < 8) errs.password = 'At least 8 characters.';
    if (!/^(\+?91)?[6-9]\d{9}$/.test(f.phone.replace(/[\s-]/g, '')) && !f.phone.startsWith('+')) errs.phone = 'Enter a valid 10-digit mobile number.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setLoading(true);
    setError('');
    try {
      await api.post('auth/register', f);
      window.location.href = window.PCRM.base + '/?welcome=1';
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors || {});
      setError(ae.message);
      setLoading(false);
    }
  };
  return (
    <Shell title="Create your studio" subtitle="Start free. No credit card required." footer={<>Already have an account? <Link to="/login" className="font-medium text-accent-ink">Sign in</Link></>}>
      {error && <Alert>{error}</Alert>}
      <GoogleButton label="Sign up with Google" />
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Your name" error={errors.name} className="col-span-2 sm:col-span-1">
            <Input value={f.name} onChange={set('name')} required autoComplete="name" />
          </Field>
          <Field label="Studio name" error={errors.studio_name} className="col-span-2 sm:col-span-1">
            <Input value={f.studio_name} onChange={set('studio_name')} required placeholder="e.g. Lens & Light Studio" />
          </Field>
        </div>
        <Field label="Work email" error={errors.email}>
          <Input type="email" value={f.email} onChange={set('email')} required autoComplete="email" />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Mobile number" error={errors.phone}>
            <div className="flex">
              <span className="inline-flex items-center rounded-l-lg border border-r-0 border-line bg-subtle px-2.5 text-sm text-muted">+91</span>
              <Input value={f.phone} onChange={set('phone')} required inputMode="tel" className="rounded-l-none" invalid={!!errors.phone} aria-label="Mobile number" />
            </div>
          </Field>
          <Field label="City" error={errors.city}>
            <Input value={f.city} onChange={set('city')} />
          </Field>
        </div>
        <Field label="Password" error={errors.password} hint="At least 8 characters.">
          <Password value={f.password} onChange={(v) => setF({ ...f, password: v })} error={errors.password} autoComplete="new-password" />
        </Field>
        <Button type="submit" variant="primary" className="w-full" loading={loading}>
          Create studio
        </Button>
      </form>
    </Shell>
  );
}

export function Forgot() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      await api.post('auth/forgot', { email });
      setSent(true);
    } catch (err) {
      setError((err as Error).message);
    }
    setLoading(false);
  };
  return (
    <Shell title="Reset your password" subtitle="We'll email you a link to set a new password." footer={<Link to="/login" className="font-medium text-accent-ink">Back to sign in</Link>}>
      {sent ? (
        <Alert tone="ok">If an account exists for {email}, a reset link is on its way. Check your inbox and spam folder.</Alert>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert>{error}</Alert>}
          <Field label="Email">
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
          </Field>
          <Button type="submit" variant="primary" className="w-full" loading={loading}>
            Send reset link
          </Button>
        </form>
      )}
    </Shell>
  );
}

export function Reset() {
  const [sp] = useSearchParams();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (password.length < 8) return setError('Password must be at least 8 characters.');
    if (password !== confirm) return setError('Passwords do not match.');
    setLoading(true);
    setError('');
    try {
      await api.post('auth/reset', { login: sp.get('login'), key: sp.get('key'), password });
      window.location.href = window.PCRM.base + '/login?reset=1';
    } catch (err) {
      setError((err as Error).message);
      setLoading(false);
    }
  };
  return (
    <Shell title="Choose a new password" footer={<Link to="/login" className="font-medium text-accent-ink">Back to sign in</Link>}>
      {error && <Alert>{error}</Alert>}
      <form onSubmit={submit} className="space-y-4">
        <Field label="New password" hint="At least 8 characters.">
          <Password value={password} onChange={setPassword} autoComplete="new-password" />
        </Field>
        <Field label="Confirm password">
          <Password value={confirm} onChange={setConfirm} autoComplete="new-password" />
        </Field>
        <Button type="submit" variant="primary" className="w-full" loading={loading}>
          Update password
        </Button>
      </form>
    </Shell>
  );
}

export function CreateStudio({ me }: { me: Me }) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [city, setCity] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('auth/create-studio', { studio_name: name, phone, city });
      window.location.href = window.PCRM.base + '/';
    } catch (err) {
      setError((err as Error).message);
      setLoading(false);
    }
  };
  return (
    <Shell
      title="Set up your studio"
      subtitle={`Signed in as ${me.user?.email}. You're not part of a studio yet.`}
      footer={
        <>
          {me.is_super && (
            <>
              <a href={window.PCRM.base + '/admin'} className="font-medium text-accent-ink">
                Open super admin
              </a>{' '}
              ·{' '}
            </>
          )}
          <button className="font-medium text-accent-ink" onClick={async () => (await api.post('auth/logout'), (window.location.href = window.PCRM.base + '/login'))}>
            Sign out
          </button>
        </>
      }
    >
      {error && <Alert>{error}</Alert>}
      <form onSubmit={submit} className="space-y-4">
        <Field label="Studio name">
          <Input value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Mobile">
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" />
          </Field>
          <Field label="City">
            <Input value={city} onChange={(e) => setCity(e.target.value)} />
          </Field>
        </div>
        <Button type="submit" variant="primary" className="w-full" loading={loading}>
          Create studio
        </Button>
        <p className="text-center text-xs text-muted">If your studio already uses ShootPilot, ask the owner to add you from Team.</p>
      </form>
    </Shell>
  );
}
