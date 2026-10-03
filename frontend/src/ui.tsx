import { useEffect, useRef, useState, type ReactNode } from 'react'
import { CX_ICONS, OUTCOME, type CxIcon, type Tone } from './format'

export function CIcon({ name, size = 18, className }: { name: CxIcon; size?: number; className?: string }) {
  return (
    <svg className={className} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d={CX_ICONS[name]} />
    </svg>
  )
}

// ------------------------------------------------------------------ Mise en page

export function PageHeader({ title, sub, actions }: { title: string; sub?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="cx-page-head">
      <div>
        <h1>{title}</h1>
        {sub && <p className="cx-sub">{sub}</p>}
      </div>
      {actions && <div className="cx-head-actions">{actions}</div>}
    </header>
  )
}

export function Card({ title, actions, children, className = '', id }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; id?: string }) {
  return (
    <section className={`cx-card ${className}`} id={id}>
      {(title || actions) && (
        <div className="cx-card-head">
          {title && <h2>{title}</h2>}
          {actions && <div className="cx-card-actions">{actions}</div>}
        </div>
      )}
      {children}
    </section>
  )
}

export function Kpi({ label, value, hint, delta, invert = false, tone }: {
  label: string; value: ReactNode; hint?: ReactNode; delta?: number | null; invert?: boolean; tone?: 'ok' | 'warn' | 'crit'
}) {
  // Une hausse est « bonne » sauf pour les erreurs et les temps (invert).
  const good = delta === null || delta === undefined ? null : invert ? delta < 0 : delta > 0
  return (
    <div className={`cx-kpi ${tone ? `cx-kpi-${tone}` : ''}`}>
      <span className="cx-kpi-label">{label}</span>
      <strong className="cx-kpi-value">{value}</strong>
      <span className="cx-kpi-hint">
        {delta !== null && delta !== undefined && (
          <span className={`cx-delta ${delta === 0 ? '' : good ? 'up' : 'down'}`}>
            {delta > 0 ? '+' : delta < 0 ? '-' : '='} {Math.abs(delta).toLocaleString('fr-CA')} %
          </span>
        )}
        {hint}
      </span>
    </div>
  )
}

export function Loading({ label = 'Chargement...' }: { label?: string }) {
  return <div className="cx-loading" role="status"><span className="cx-spinner" />{label}</div>
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="cx-error" role="alert">
      <CIcon name="alert" />
      <span>{message}</span>
      {onRetry && <button className="cx-btn cx-btn-ghost" onClick={onRetry}>Réessayer</button>}
    </div>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="cx-empty">{children}</div>
}

/** Etat lisible : icone + libelle (jamais la couleur seule). */
export function Status({ tone, children }: { tone: Tone; children: ReactNode }) {
  const icon: CxIcon = tone === 'ok' ? 'check' : tone === 'crit' ? 'x' : tone === 'warn' ? 'alert' : tone === 'info' ? 'info' : 'minus'
  return <span className={`cx-status cx-status-${tone}`}><CIcon name={icon} size={13} />{children}</span>
}

export function Badge({ tone = 'muted', children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`cx-badge cx-badge-${tone}`}>{children}</span>
}

export function Outcome({ value }: { value: string | null | undefined }) {
  if (!value) return <span className="cx-muted">-</span>
  const [tone, label] = OUTCOME[value] ?? ['muted', value]
  return <Status tone={tone}>{label}</Status>
}

export function PeriodPicker({ value, onChange, options = ['24h', '7d', '30d'] }: { value: string; onChange: (v: string) => void; options?: string[] }) {
  const labels: Record<string, string> = { '24h': '24 heures', '7d': '7 jours', '30d': '30 jours', '90d': '90 jours' }
  return (
    <div className="cx-segment" role="group" aria-label="Période">
      {options.map((o) => (
        <button key={o} className={value === o ? 'on' : ''} aria-pressed={value === o} onClick={() => onChange(o)}>{labels[o] ?? o}</button>
      ))}
    </div>
  )
}

// ------------------------------------------------------------------ Fenetre de confirmation

export function Dialog({ title, children, onClose, footer, wide = false }: {
  title: string; children: ReactNode; onClose: () => void; footer?: ReactNode; wide?: boolean
}) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    ref.current?.querySelector<HTMLElement>('input, textarea, select, button.cx-btn-primary, button.cx-btn-danger')?.focus()
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])
  return (
    <div className="cx-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div className={`cx-dialog ${wide ? 'wide' : ''}`} role="dialog" aria-modal="true" aria-label={title} ref={ref}>
        <div className="cx-dialog-head">
          <h2>{title}</h2>
          <button className="cx-icon-btn" onClick={onClose} aria-label="Fermer"><CIcon name="x" /></button>
        </div>
        <div className="cx-dialog-body">{children}</div>
        {footer && <div className="cx-dialog-foot">{footer}</div>}
      </div>
    </div>
  )
}

/** Confirmation d'une action : texte explicatif, champ facultatif, bouton occupe pendant l'envoi. */
export function Confirm({ title, children, confirmLabel, danger = false, onConfirm, onClose, disabled = false }: {
  title: string; children: ReactNode; confirmLabel: string; danger?: boolean; disabled?: boolean
  onConfirm: () => Promise<unknown>; onClose: () => void
}) {
  const [busy, setBusy] = useState(false)
  const run = async () => {
    setBusy(true)
    try { await onConfirm(); onClose() } catch { /* message deja affiche */ } finally { setBusy(false) }
  }
  return (
    <Dialog title={title} onClose={onClose} footer={(
      <>
        <button className="cx-btn cx-btn-ghost" onClick={onClose} disabled={busy}>Annuler</button>
        <button className={`cx-btn ${danger ? 'cx-btn-danger' : 'cx-btn-primary'}`} onClick={run} disabled={busy || disabled}>
          {busy ? <span className="cx-spinner" /> : confirmLabel}
        </button>
      </>
    )}>
      {children}
    </Dialog>
  )
}

/** Tableau de donnees d'un graphique (lecture sans la couleur, lecteurs d'ecran). */
export function DataTable({ head, rows }: { head: string[]; rows: (string | number)[][] }) {
  return (
    <details className="cx-data">
      <summary>Voir les données</summary>
      <div className="cx-table-wrap">
        <table className="cx-table compact">
          <thead><tr>{head.map((h) => <th key={h}>{h}</th>)}</tr></thead>
          <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j}>{c}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </details>
  )
}

/** Valeurs techniques (contexte d'un evenement, details) en liste lisible. */
export function KeyValues({ data }: { data: Record<string, unknown> | null | undefined }) {
  if (!data || Object.keys(data).length === 0) return <p className="cx-muted">Aucun détail.</p>
  return (
    <dl className="cx-kv">
      {Object.entries(data).map(([k, v]) => (
        <div key={k}>
          <dt>{k}</dt>
          <dd>{v === null || v === undefined ? '-' : typeof v === 'object'
            ? <pre>{JSON.stringify(v, null, 2)}</pre>
            : String(v)}</dd>
        </div>
      ))}
    </dl>
  )
}
