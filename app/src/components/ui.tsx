import { cloneElement, isValidElement, useId, type ReactElement, forwardRef, useEffect, useRef, useState, createContext, useContext, useCallback, type ReactNode, type ButtonHTMLAttributes, type InputHTMLAttributes, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { createPortal } from 'react-dom';
import clsx from 'clsx';
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Info, Loader2, Search, X, XCircle } from 'lucide-react';
import { initials } from '@/lib/format';

/* ---------- Buttons ---------- */
type BtnProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'success';
  size?: 'xs' | 'sm' | 'md';
  loading?: boolean;
  icon?: ReactNode;
};
export const Button = forwardRef<HTMLButtonElement, BtnProps>(function Button({ variant = 'secondary', size = 'md', loading, icon, className, children, disabled, ...rest }, ref) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={clsx(
        'inline-flex select-none items-center justify-center gap-1.5 whitespace-nowrap rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        size === 'xs' && 'h-7 px-2 text-xs',
        size === 'sm' && 'h-8 px-3 text-xs',
        size === 'md' && 'h-9 px-3.5 text-sm',
        variant === 'primary' && 'bg-accent text-white shadow-sm hover:bg-accent/90 dark:text-slate-950',
        variant === 'secondary' && 'border border-line bg-surface text-ink shadow-sm hover:bg-subtle',
        variant === 'ghost' && 'text-muted hover:bg-subtle hover:text-ink',
        variant === 'danger' && 'bg-red-600 text-white shadow-sm hover:bg-red-700',
        variant === 'success' && 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700',
        className,
      )}
      {...rest}
    >
      {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : icon}
      {children}
    </button>
  );
});

export function IconButton({ className, label, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return <button aria-label={label} title={label} className={clsx('inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted transition-colors hover:bg-subtle hover:text-ink', className)} {...rest} />;
}

/* ---------- Form controls ---------- */
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(function Input({ className, invalid, ...rest }, ref) {
  return <input ref={ref} className={clsx('input', invalid && 'input-error', className)} {...rest} />;
});
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea({ className, invalid, ...rest }, ref) {
  return <textarea ref={ref} className={clsx('input min-h-[84px] resize-y', invalid && 'input-error', className)} {...rest} />;
});
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean; options?: Record<string, string> | [string, string][]; placeholder?: string }>(function Select(
  { className, invalid, options, placeholder, children, ...rest },
  ref,
) {
  const entries = options ? (Array.isArray(options) ? options : Object.entries(options)) : [];
  return (
    <select ref={ref} className={clsx('input appearance-none bg-[length:16px] bg-[right_0.6rem_center] bg-no-repeat pr-8', invalid && 'input-error', className)} style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }} {...rest}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {entries.map(([k, v]) => (
        <option key={k} value={k}>
          {v}
        </option>
      ))}
      {children}
    </select>
  );
});

export function Field({ label, error, hint, children, className, required }: { label?: ReactNode; error?: string; hint?: ReactNode; children: ReactNode; className?: string; required?: boolean }) {
  // Tie the label to a single form control so screen readers (and tests) can find it by label.
  const autoId = useId();
  const single = isValidElement(children) && typeof children.type !== 'string' ? true : isValidElement(children) && ['input', 'select', 'textarea'].includes(children.type as string);
  const ctrlId = single ? ((children as ReactElement<any>).props.id as string) || autoId : undefined;
  if (single && ctrlId === autoId) children = cloneElement(children as ReactElement<any>, { id: autoId });
  return (
    <div className={className}>
      {label && (
        <label className="label" htmlFor={ctrlId}>
          {label}
          {required && <span className="text-red-500"> *</span>}
        </label>
      )}
      {children}
      {error ? <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p> : hint ? <p className="mt-1 text-xs text-faint">{hint}</p> : null}
    </div>
  );
}

export function Checkbox({ checked, onChange, label, className, indeterminate }: { checked: boolean; onChange: (v: boolean) => void; label?: ReactNode; className?: string; indeterminate?: boolean }) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = !!indeterminate;
  }, [indeterminate]);
  return (
    <label className={clsx('inline-flex cursor-pointer items-center gap-2 text-sm', className)}>
      <input ref={ref} type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 rounded border-line accent-[rgb(var(--accent))]" />
      {label}
    </label>
  );
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label?: ReactNode }) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-2.5 text-sm">
      <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className={clsx('relative h-5 w-9 rounded-full transition-colors', checked ? 'bg-accent' : 'bg-line')}>
        <span className={clsx('absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all', checked ? 'left-[18px]' : 'left-0.5')} />
      </button>
      {label}
    </label>
  );
}

export function SearchInput({ value, onChange, placeholder = 'Search…', className }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string }) {
  return (
    <div className={clsx('relative', className)}>
      <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
      <input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="input pl-8" />
      {value && (
        <button onClick={() => onChange('')} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-faint hover:text-ink" aria-label="Clear search">
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  );
}

export function Segmented<T extends string>({ value, onChange, options, size = 'md' }: { value: T; onChange: (v: T) => void; options: [T, ReactNode][]; size?: 'sm' | 'md' }) {
  return (
    <div className="inline-flex rounded-lg border border-line bg-subtle p-0.5">
      {options.map(([k, l]) => (
        <button
          key={k}
          type="button"
          onClick={() => onChange(k)}
          className={clsx('rounded-md font-medium transition-colors', size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-xs sm:text-sm', value === k ? 'bg-surface text-ink shadow-sm' : 'text-muted hover:text-ink')}
        >
          {l}
        </button>
      ))}
    </div>
  );
}

/* ---------- Display ---------- */
export function Card({ className, children, ...rest }: { className?: string; children: ReactNode } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={clsx('card', className)} {...rest}>
      {children}
    </div>
  );
}
export function CardHeader({ title, subtitle, actions, className }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={clsx('flex items-start justify-between gap-3 border-b border-line px-5 py-3.5', className)}>
      <div className="min-w-0">
        <h3 className="truncate text-sm font-semibold">{title}</h3>
        {subtitle && <p className="mt-0.5 text-xs text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  );
}

const tones: Record<string, string> = {
  gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  blue: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
  indigo: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
  accent: 'bg-accent-soft text-accent-ink',
  violet: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  cyan: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
  green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  orange: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
  red: 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
  pink: 'bg-pink-50 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300',
};
export type Tone = keyof typeof tones;
export function Badge({ tone = 'gray', children, className, dot }: { tone?: Tone | string; children: ReactNode; className?: string; dot?: boolean }) {
  return (
    <span className={clsx('inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-2xs font-medium', tones[tone] || tones.gray, className)}>
      {dot && <span className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />}
      {children}
    </span>
  );
}

export function Avatar({ name, color, size = 'md', className, src }: { name?: string | null; color?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'; className?: string; src?: string | null }) {
  const dims = clsx(size === 'xs' && 'h-5 w-5', size === 'sm' && 'h-6 w-6', size === 'md' && 'h-8 w-8', size === 'lg' && 'h-12 w-12', size === 'xl' && 'h-20 w-20');
  if (src) return <img src={src} alt={name || ''} title={name || undefined} className={clsx('inline-block shrink-0 rounded-full object-cover ring-2 ring-surface', dims, className)} />;
  return (
    <span
      title={name || undefined}
      className={clsx(
        'inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white ring-2 ring-surface',
        size === 'xs' && 'h-5 w-5 text-[9px]',
        size === 'sm' && 'h-6 w-6 text-[10px]',
        size === 'md' && 'h-8 w-8 text-xs',
        size === 'lg' && 'h-12 w-12 text-base',
        size === 'xl' && 'h-20 w-20 text-2xl',
        className,
      )}
      style={{ background: color || '#6366f1' }}
    >
      {initials(name)}
    </span>
  );
}

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={clsx('h-5 w-5 animate-spin text-accent-ink', className)} />;
}
export function PageLoader() {
  return (
    <div className="flex h-64 items-center justify-center">
      <Spinner className="h-6 w-6" />
    </div>
  );
}
export function Skeleton({ className }: { className?: string }) {
  return <div className={clsx('animate-pulse rounded-md bg-subtle', className)} />;
}
export function TableSkeleton({ rows = 6, cols = 5 }: { rows?: number; cols?: number }) {
  return (
    <div className="divide-y divide-line">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex gap-4 px-4 py-3.5">
          {Array.from({ length: cols }).map((_, j) => (
            <Skeleton key={j} className={clsx('h-4', j === 0 ? 'w-40' : 'flex-1')} />
          ))}
        </div>
      ))}
    </div>
  );
}

export function EmptyState({ icon, title, description, action, className }: { icon?: ReactNode; title: string; description?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={clsx('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
      {icon && <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-accent-soft text-accent-ink">{icon}</div>}
      <p className="text-sm font-semibold">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
      <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-red-50 text-red-600 dark:bg-red-500/15">
        <AlertTriangle className="h-5 w-5" />
      </div>
      <p className="text-sm font-semibold">Couldn't load this</p>
      <p className="mt-1 max-w-sm text-sm text-muted">{error instanceof Error ? error.message : 'Something went wrong.'}</p>
      {onRetry && (
        <Button className="mt-4" size="sm" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  );
}

export function StatCard({ label, value, sub, icon, tone = 'indigo', onClick }: { label: string; value: ReactNode; sub?: ReactNode; icon?: ReactNode; tone?: Tone; onClick?: () => void }) {
  return (
    <button type="button" onClick={onClick} disabled={!onClick} className={clsx('card flex w-full items-start gap-3 p-4 text-left transition-shadow', onClick && 'hover:shadow-pop')}>
      {icon && <span className={clsx('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', tones[tone])}>{icon}</span>}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-xs font-medium text-muted">{label}</span>
        <span className="tabular mt-0.5 block truncate text-xl font-semibold tracking-tight">{value}</span>
        {sub && <span className="mt-0.5 block truncate text-xs text-faint">{sub}</span>}
      </span>
    </button>
  );
}

export function PageHeader({ title, subtitle, actions, back }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; back?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {back}
        <h1 className="truncate text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Tabs<T extends string>({ value, onChange, tabs, className }: { value: T; onChange: (v: T) => void; tabs: [T, ReactNode, (number | null)?][]; className?: string }) {
  return (
    <div className={clsx('scrollbar-thin -mb-px flex gap-1 overflow-x-auto border-b border-line', className)}>
      {tabs.map(([k, label, count]) => (
        <button
          key={k}
          onClick={() => onChange(k)}
          className={clsx('flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors', value === k ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink')}
        >
          {label}
          {count !== undefined && count !== null && <span className={clsx('rounded-full px-1.5 text-2xs', value === k ? 'bg-accent-soft text-accent-ink' : 'bg-subtle text-muted')}>{count}</span>}
        </button>
      ))}
    </div>
  );
}

export function Pagination({ page, perPage, total, onPage }: { page: number; perPage: number; total: number; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / perPage));
  if (total <= perPage) return total ? <div className="px-4 py-3 text-xs text-muted">{total} records</div> : null;
  return (
    <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-xs text-muted">
      <span className="tabular">
        {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total}
      </span>
      <div className="flex items-center gap-1">
        <IconButton label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)} className="disabled:opacity-40">
          <ChevronLeft className="h-4 w-4" />
        </IconButton>
        <span className="tabular px-2">
          {page} / {pages}
        </span>
        <IconButton label="Next page" disabled={page >= pages} onClick={() => onPage(page + 1)} className="disabled:opacity-40">
          <ChevronRight className="h-4 w-4" />
        </IconButton>
      </div>
    </div>
  );
}

/* ---------- Overlays ---------- */
function useEscape(onClose: () => void, open: boolean) {
  useEffect(() => {
    if (!open) return;
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', h);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);
}

export function Modal({ open, onClose, title, subtitle, children, footer, size = 'md' }: { open: boolean; onClose: () => void; title: ReactNode; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
  useEscape(onClose, open);
  if (!open) return null;
  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-[2px]" onClick={onClose} />
      <div
        className={clsx(
          'relative flex max-h-[92vh] w-full flex-col rounded-t-2xl border border-line bg-surface shadow-pop sm:rounded-2xl',
          size === 'sm' && 'sm:max-w-md',
          size === 'md' && 'sm:max-w-lg',
          size === 'lg' && 'sm:max-w-2xl',
          size === 'xl' && 'sm:max-w-4xl',
        )}
      >
        <div className="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
          <div>
            <h2 className="text-base font-semibold">{title}</h2>
            {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
          </div>
          <IconButton label="Close" onClick={onClose} className="-mr-2 -mt-1">
            <X className="h-4 w-4" />
          </IconButton>
        </div>
        <div className="scrollbar-thin flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
      </div>
    </div>,
    document.body,
  );
}

export function Drawer({ open, onClose, title, children, footer, width = 'max-w-xl' }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode; width?: string }) {
  useEscape(onClose, open);
  if (!open) return null;
  return createPortal(
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-slate-950/40 backdrop-blur-[2px]" onClick={onClose} />
      <div className={clsx('relative flex h-full w-full flex-col border-l border-line bg-surface shadow-pop', width)}>
        <div className="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
          <h2 className="truncate text-base font-semibold">{title}</h2>
          <IconButton label="Close" onClick={onClose}>
            <X className="h-4 w-4" />
          </IconButton>
        </div>
        <div className="scrollbar-thin flex-1 overflow-y-auto">{children}</div>
        {footer && <div className="flex items-center justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
      </div>
    </div>,
    document.body,
  );
}

export function Menu({ trigger, children, align = 'right' }: { trigger: (open: boolean) => ReactNode; children: (close: () => void) => ReactNode; align?: 'left' | 'right' }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const h = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [open]);
  return (
    <div className="relative" ref={ref}>
      <div onClick={() => setOpen((o) => !o)}>{trigger(open)}</div>
      {open && <div className={clsx('absolute z-40 mt-1 min-w-[190px] rounded-xl border border-line bg-surface p-1 shadow-pop', align === 'right' ? 'right-0' : 'left-0')}>{children(() => setOpen(false))}</div>}
    </div>
  );
}
export function MenuItem({ onClick, icon, children, danger, disabled }: { onClick?: () => void; icon?: ReactNode; children: ReactNode; danger?: boolean; disabled?: boolean }) {
  return (
    <button disabled={disabled} onClick={onClick} className={clsx('flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition-colors disabled:opacity-50', danger ? 'text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10' : 'hover:bg-subtle')}>
      {icon && <span className="text-muted [&>svg]:h-4 [&>svg]:w-4">{icon}</span>}
      {children}
    </button>
  );
}

/* ---------- Toasts & confirm ---------- */
type ToastT = { id: number; type: 'success' | 'error' | 'info'; message: string };
type UiCtx = { toast: (message: string, type?: ToastT['type']) => void; confirm: (o: ConfirmOpts) => Promise<boolean> };
type ConfirmOpts = { title: string; message?: ReactNode; confirmLabel?: string; danger?: boolean };
const UiContext = createContext<UiCtx | null>(null);

export function UiProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastT[]>([]);
  const [dlg, setDlg] = useState<(ConfirmOpts & { resolve: (v: boolean) => void }) | null>(null);
  const toast = useCallback((message: string, type: ToastT['type'] = 'success') => {
    const id = Date.now() + Math.random();
    setToasts((t) => [...t, { id, type, message }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), type === 'error' ? 6000 : 3500);
  }, []);
  const confirm = useCallback((o: ConfirmOpts) => new Promise<boolean>((resolve) => setDlg({ ...o, resolve })), []);
  const close = (v: boolean) => {
    dlg?.resolve(v);
    setDlg(null);
  };
  return (
    <UiContext.Provider value={{ toast, confirm }}>
      {children}
      {createPortal(
        <div className="pointer-events-none fixed bottom-4 left-1/2 z-[60] flex w-[min(92vw,380px)] -translate-x-1/2 flex-col gap-2 sm:left-auto sm:right-4 sm:translate-x-0">
          {toasts.map((t) => (
            <div key={t.id} className="pointer-events-auto flex items-start gap-2.5 rounded-xl border border-line bg-surface px-3.5 py-3 text-sm shadow-pop">
              {t.type === 'success' && <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />}
              {t.type === 'error' && <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />}
              {t.type === 'info' && <Info className="mt-0.5 h-4 w-4 shrink-0 text-accent-ink" />}
              <span className="flex-1">{t.message}</span>
            </div>
          ))}
        </div>,
        document.body,
      )}
      <Modal
        open={!!dlg}
        onClose={() => close(false)}
        title={dlg?.title}
        size="sm"
        footer={
          <>
            <Button onClick={() => close(false)}>Cancel</Button>
            <Button variant={dlg?.danger ? 'danger' : 'primary'} onClick={() => close(true)} autoFocus>
              {dlg?.confirmLabel || 'Confirm'}
            </Button>
          </>
        }
      >
        <div className="text-sm text-muted">{dlg?.message}</div>
      </Modal>
    </UiContext.Provider>
  );
}

export function useUi() {
  const c = useContext(UiContext);
  if (!c) throw new Error('useUi outside provider');
  return c;
}

/** ShootPilot mark: a plain aperture in the brand colour. */
export function Logo({ className = 'h-8 w-8' }: { className?: string }) {
  return (
    <svg viewBox="0 0 100 100" className={className} aria-hidden="true">
      <rect width="100" height="100" rx="24" fill="#F3724C" />
      <g fill="none" stroke="#fff" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="50" cy="50" r="27" />
        <path d="M50.00 61.00L23.36 45.62 M40.47 55.50L40.47 24.74 M40.47 44.50L67.12 29.12 M50.00 39.00L76.64 54.38 M59.53 44.50L59.53 75.26 M59.53 55.50L32.88 70.88" />
      </g>
    </svg>
  );
}
