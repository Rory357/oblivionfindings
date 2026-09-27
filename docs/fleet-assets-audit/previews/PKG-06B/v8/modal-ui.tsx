import React, { createContext, type ReactNode } from 'react';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ArrowLeft, CheckCircle2, LockKeyhole, X } from 'lucide-react';

export const FieldErrors = createContext<Record<string, string>>({});

export function ModalFrame({title, description, icon: Icon, width = 720, onClose, backLabel, closeLabel, actions, tools, busy, success, children}: {
  title: string; description: string; icon: React.ComponentType<{size?: number}>;
  width?: number; onClose: () => void; backLabel?: string; closeLabel?: string; actions?: ReactNode;
  tools?: ReactNode; busy?: boolean; success?: string; children: ReactNode;
}) {
  return <Dialog open onOpenChange={open => !open && onClose()}>
    <DialogContent className="asset-modal" showCloseButton={false} style={{width:`min(92vw, ${width}px)`, maxWidth:`min(92vw, ${width}px)`}}>
      <header className="asset-modal-heading">
        <span className="asset-modal-icon"><Icon size={22}/></span>
        <div><DialogTitle>{title}</DialogTitle><DialogDescription>{description}</DialogDescription></div>
        <Button variant="ghost" size="icon" aria-label="Close dialog" disabled={busy} onClick={onClose}><X size={18}/></Button>
      </header>
      <div className="asset-modal-body">
        {success ? <div className="asset-modal-success" role="status"><CheckCircle2 size={36}/><h2 className="text-section-title">Recorded in this preview</h2><p>{success}</p></div> : <fieldset className="modal-form" disabled={busy}>{children}</fieldset>}
      </div>
      <footer className="asset-modal-footer">
        <Button variant="outline" disabled={busy} onClick={onClose}>{backLabel ? <><ArrowLeft size={15}/>{backLabel}</> : success ? 'Return to asset' : closeLabel || 'Close'}</Button>
        {!success && <div className="asset-modal-actions">{tools}{actions}</div>}
      </footer>
      <div className="asset-modal-scope"><LockKeyhole size={12}/>Synthetic preview · Changes stay in this tab</div>
    </DialogContent>
  </Dialog>;
}
