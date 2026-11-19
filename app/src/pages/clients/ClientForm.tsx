import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import { useForm, validate } from '@/lib/form';
import { Button, Field, Input, Modal, Textarea, useUi } from '@/components/ui';

export function ClientFormModal({ open, onClose, client, onSaved }: { open: boolean; onClose: () => void; client?: any; onSaved?: (c: any) => void }) {
  const { toast } = useUi();
  const qc = useQueryClient();
  const f = useForm({ name: '', phone: '', whatsapp: '', email: '', city: '', address: '', notes: '' });
  const [busy, setBusy] = useState(false);
  const [dup, setDup] = useState<number | null>(null);
  useEffect(() => {
    if (!open) return;
    f.setValues(client ? { name: client.name, phone: client.phone || '', whatsapp: client.whatsapp || '', email: client.email || '', city: client.city || '', address: client.address || '', notes: client.notes || '' } : { name: '', phone: '', whatsapp: '', email: '', city: '', address: '', notes: '' });
    f.setErrors({});
    setDup(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, client?.id]);
  const save = async () => {
    const e = validate(f.values, { name: ['required'], phone: ['required', 'phone'], whatsapp: ['phone'], email: ['email'] }, { name: 'Client name', phone: 'Phone number' });
    f.setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      const body = Object.fromEntries(Object.entries(f.values).map(([k, v]) => [k, v === '' ? null : v]));
      const r = client ? await api.put(`clients/${client.id}`, body) : await api.post('clients', body);
      toast(client ? 'Client updated' : 'Client added');
      ['clients', 'client'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      onClose();
      onSaved?.(r);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) setDup(err.data?.client_id || null);
      f.fromError(err);
      toast((err as Error).message, 'error');
    }
    setBusy(false);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={client ? 'Edit client' : 'New client'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={save}>Save</Button>
        </>
      }
    >
      {dup && <p className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">This phone number already has a client profile. <Link to={`/clients/${dup}`} onClick={onClose} className="font-medium underline">Open it</Link></p>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Client name" required error={f.errors.name} className="sm:col-span-2"><Input {...f.bind('name')} autoFocus /></Field>
        <Field label="Phone" required error={f.errors.phone}><Input {...f.bind('phone')} inputMode="tel" /></Field>
        <Field label="WhatsApp" error={f.errors.whatsapp} hint="Leave blank if same as phone"><Input {...f.bind('whatsapp')} inputMode="tel" /></Field>
        <Field label="Email" error={f.errors.email}><Input type="email" {...f.bind('email')} /></Field>
        <Field label="City"><Input {...f.bind('city')} /></Field>
        <Field label="Address" className="sm:col-span-2"><Textarea rows={2} {...f.bind('address')} /></Field>
        <Field label="Notes" className="sm:col-span-2"><Textarea rows={2} {...f.bind('notes')} /></Field>
      </div>
    </Modal>
  );
}
