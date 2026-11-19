import { useEffect, useState, type ReactNode } from 'react';
import { inr, inrShort } from '@/lib/format';

/** Tracks the html.dark class so charts pick their dark-mode steps. */
export function useIsDark() {
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  useEffect(() => {
    const o = new MutationObserver(() => setDark(document.documentElement.classList.contains('dark')));
    o.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => o.disconnect();
  }, []);
  return dark;
}

export const axisProps = {
  tick: { fill: 'rgb(var(--muted))', fontSize: 11 },
  tickLine: false,
  axisLine: false,
} as const;

export const gridProps = { stroke: 'rgb(var(--line))', strokeDasharray: '0', vertical: false } as const;

export const moneyTick = (v: number) => inrShort(v);

/** Tooltip body: text in ink tokens, a colour swatch carries identity. */
export function ChartTooltip({ active, payload, label, money, labelFormat }: any & { money?: boolean; labelFormat?: (l: any) => ReactNode }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-pop">
      {label !== undefined && <p className="mb-1 font-semibold text-ink">{labelFormat ? labelFormat(label) : label}</p>}
      {payload.map((p: any) => (
        <p key={p.dataKey ?? p.name} className="flex items-center gap-2 text-muted">
          <span className="h-2 w-2 rounded-full" style={{ background: p.color || p.payload?.fill }} />
          <span>{p.name}</span>
          <span className="tabular ml-auto pl-3 font-semibold text-ink">{money ? inr(p.value) : p.value}</span>
        </p>
      ))}
    </div>
  );
}

export function Legend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
      {items.map((i) => (
        <span key={i.label} className="inline-flex items-center gap-1.5">
          <span className="h-2.5 w-2.5 rounded-sm" style={{ background: i.color }} />
          {i.label}
        </span>
      ))}
    </div>
  );
}
