import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import Papa from 'papaparse';
import { FileSpreadsheet, Upload } from 'lucide-react';
import { api, saveBlob } from '@/lib/api';
import { Button, Checkbox, Modal, Select, useUi } from '@/components/ui';

const FIELDS: [string, string, string[]][] = [
  ['name', 'Client Name', ['name', 'client name', 'client', 'customer', 'customer name', 'full name']],
  ['phone', 'Phone Number', ['phone', 'phone number', 'mobile', 'mobile number', 'contact', 'contact number']],
  ['whatsapp', 'WhatsApp Number', ['whatsapp', 'whatsapp number', 'wa']],
  ['email', 'Email', ['email', 'email address', 'e-mail']],
  ['event_type', 'Event Type', ['event type', 'event', 'type', 'occasion']],
  ['event_date', 'Event Date', ['event date', 'date', 'wedding date']],
  ['event_end_date', 'Event End Date', ['event end date', 'end date']],
  ['event_duration', 'Event Duration', ['duration', 'event duration']],
  ['venue', 'Venue Name', ['venue', 'venue name', 'location']],
  ['city', 'City', ['city', 'town']],
  ['source', 'Lead Source', ['source', 'lead source', 'channel']],
  ['budget', 'Estimated Budget', ['budget', 'estimated budget', 'amount']],
  ['stage', 'Lead Status', ['status', 'lead status', 'stage']],
  ['priority', 'Priority', ['priority']],
  ['notes', 'Notes', ['notes', 'note', 'remarks', 'comments']],
];

function toIsoDate(v: any): string {
  if (v instanceof Date) return v.toISOString().slice(0, 10);
  const s = String(v ?? '').trim();
  let m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
  if (m) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? m[0] : s;
}

export function ImportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const [headers, setHeaders] = useState<string[]>([]);
  const [rows, setRows] = useState<any[][]>([]);
  const [map, setMap] = useState<Record<string, string>>({});
  const [skip, setSkip] = useState(true);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);
  const [fileName, setFileName] = useState('');

  const reset = () => {
    setHeaders([]);
    setRows([]);
    setMap({});
    setResult(null);
    setFileName('');
  };
  const close = () => (reset(), onClose());

  const autoMap = (hs: string[]) => {
    const m: Record<string, string> = {};
    FIELDS.forEach(([key, , aliases]) => {
      const idx = hs.findIndex((h) => aliases.includes(h.trim().toLowerCase()));
      if (idx >= 0) m[key] = String(idx);
    });
    setMap(m);
  };

  const onFile = async (file: File) => {
    setFileName(file.name);
    setResult(null);
    try {
      let data: any[][] = [];
      if (/\.xlsx$/i.test(file.name)) {
        const readXlsx = (await import('read-excel-file')).default;
        data = (await readXlsx(file)) as any[][];
      } else {
        const text = await file.text();
        data = (Papa.parse(text, { skipEmptyLines: true }).data as any[][]) || [];
      }
      if (data.length < 2) return toast('The file needs a header row and at least one lead.', 'error');
      const hs = data[0].map((h) => String(h ?? ''));
      setHeaders(hs);
      setRows(data.slice(1).filter((r) => r.some((c) => String(c ?? '').trim())));
      autoMap(hs);
    } catch {
      toast('Could not read this file. Use .csv or .xlsx.', 'error');
    }
  };

  const run = async () => {
    if (!map.name || !map.phone) return toast('Map at least Client Name and Phone Number.', 'error');
    const payload = rows.map((r) => {
      const o: Record<string, any> = {};
      Object.entries(map).forEach(([k, idx]) => {
        if (idx === '') return;
        let v = r[Number(idx)];
        if (['event_date', 'event_end_date'].includes(k) && v) v = toIsoDate(v);
        o[k] = v instanceof Date ? v.toISOString().slice(0, 10) : v === null || v === undefined ? '' : String(v);
      });
      return o;
    });
    setBusy(true);
    try {
      const r = await api.post('leads/import', { rows: payload, skip_duplicates: skip });
      setResult(r);
      ['leads', 'pipeline', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
    } catch (e: any) {
      toast(e.message, 'error');
    }
    setBusy(false);
  };

  const template = () => {
    const csv = FIELDS.map((f) => f[1]).join(',') + '\n' + 'Ananya & Rohan Sharma,9876543210,9876543210,ananya@gmail.com,Wedding,15-02-2027,17-02-2027,3 days,Taj Lands End,Mumbai,Instagram,450000,New Lead,High,Wants candid + cinematic\n';
    saveBlob(new Blob(['﻿' + csv], { type: 'text/csv' }), 'leads-import-template.csv');
  };

  return (
    <Modal
      open={open}
      onClose={close}
      size="lg"
      title="Import leads"
      subtitle="Upload a CSV or Excel (.xlsx) file. Dates can be DD-MM-YYYY."
      footer={
        result ? (
          <Button variant="primary" onClick={close}>Done</Button>
        ) : (
          <>
            <Button onClick={close}>Cancel</Button>
            <Button variant="primary" disabled={!rows.length} loading={busy} onClick={run}>
              Import {rows.length ? rows.length + ' rows' : ''}
            </Button>
          </>
        )
      }
    >
      {result ? (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-500/10"><p className="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{result.created}</p><p className="text-xs text-muted">Imported</p></div>
            <div className="rounded-lg bg-amber-50 p-3 dark:bg-amber-500/10"><p className="text-2xl font-semibold text-amber-700 dark:text-amber-300">{result.skipped}</p><p className="text-xs text-muted">Duplicates skipped</p></div>
            <div className="rounded-lg bg-red-50 p-3 dark:bg-red-500/10"><p className="text-2xl font-semibold text-red-700 dark:text-red-300">{result.errors.length}</p><p className="text-xs text-muted">Errors</p></div>
          </div>
          {result.errors.length > 0 && (
            <ul className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-line p-3 text-xs">
              {result.errors.map((e: any, i: number) => <li key={i}><b>Row {e.row}:</b> {e.message}</li>)}
            </ul>
          )}
        </div>
      ) : !rows.length ? (
        <div className="space-y-4">
          <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-line px-6 py-10 text-center hover:bg-subtle">
            <Upload className="h-6 w-6 text-accent-ink" />
            <span className="text-sm font-medium">Choose a file</span>
            <span className="text-xs text-muted">CSV or XLSX, up to 5,000 rows</span>
            <input type="file" accept=".csv,.xlsx,text/csv" className="hidden" onChange={(e) => e.target.files?.[0] && onFile(e.target.files[0])} />
          </label>
          <button onClick={template} className="flex items-center gap-1.5 text-sm font-medium text-accent-ink"><FileSpreadsheet className="h-4 w-4" /> Download sample template</button>
        </div>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-muted"><b className="text-ink">{fileName}</b> · {rows.length} rows found. Match your columns:</p>
          <div className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
            {FIELDS.map(([key, label]) => (
              <div key={key} className="flex items-center gap-2">
                <span className="w-32 shrink-0 text-sm">{label}{['name', 'phone'].includes(key) && <span className="text-red-500"> *</span>}</span>
                <Select value={map[key] ?? ''} onChange={(e) => setMap({ ...map, [key]: e.target.value })} options={headers.map((h, i) => [String(i), h || `Column ${i + 1}`] as [string, string])} placeholder="— Skip —" className="py-1.5" />
              </div>
            ))}
          </div>
          <Checkbox checked={skip} onChange={setSkip} label="Skip leads whose phone or email already exists" />
          <div className="overflow-x-auto rounded-lg border border-line">
            <table className="w-full text-xs">
              <thead className="bg-subtle"><tr>{headers.map((h, i) => <th key={i} className="whitespace-nowrap px-2 py-1.5 text-left font-medium">{h}</th>)}</tr></thead>
              <tbody>{rows.slice(0, 4).map((r, i) => <tr key={i} className="border-t border-line">{headers.map((_, j) => <td key={j} className="whitespace-nowrap px-2 py-1.5 text-muted">{String(r[j] instanceof Date ? (r[j] as Date).toISOString().slice(0, 10) : r[j] ?? '')}</td>)}</tr>)}</tbody>
            </table>
          </div>
          <button onClick={reset} className="text-xs text-muted hover:text-ink">Choose a different file</button>
        </div>
      )}
    </Modal>
  );
}
