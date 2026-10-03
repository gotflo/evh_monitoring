import { useState } from 'react'
import { AsYouType, isValidPhoneNumber, type CountryCode } from 'libphonenumber-js'
import { CountrySelect } from '../components/CountrySelect'
import { DEFAULT_COUNTRY, type Country } from '../data/countries'
import { capi } from '../api'
import { useConsole, type ConsoleRole } from '../auth'
import { Badge, Card, Confirm, Empty, ErrorState, Loading, PageHeader, Status } from '../ui'
import { fmtDate, fmtRel, useData } from '../format'

interface Op {
  id: number; name: string | null; phone: string; role: ConsoleRole; role_label: string; is_primary: boolean; status: 'invited' | 'active' | 'revoked'
  alerts_enabled: boolean; invited_by: string | null; created_at: string | null; activated_at: string | null; last_login_at: string | null
  revoked_at: string | null; sessions: number; last_activity_at: string | null; has_member_account: boolean; is_me: boolean
}
interface Data { operators: Op[]; roles: { key: ConsoleRole; label: string; help: string }[] }

const STATUS: Record<Op['status'], ['ok' | 'info' | 'muted', string]> = { active: ['ok', 'Actif'], invited: ['info', 'Invité (jamais connecté)'], revoked: ['muted', 'Accès retiré'] }

export default function Access() {
  const { can } = useConsole()
  const owner = can('owner')
  const { data, error, loading, reload } = useData<Data>('/access')
  const [confirm, setConfirm] = useState<{ op: Op; kind: 'revoke' | 'sessions' } | null>(null)

  const patch = async (op: Op, body: Partial<Pick<Op, 'role' | 'alerts_enabled' | 'name'>>) => {
    try { await capi(`/access/${op.id}`, { method: 'PATCH', body }); reload() } catch { /* message affiche */ }
  }

  return (
    <>
      <PageHeader title="Accès à la console" sub="Seuls les numéros listés ici peuvent se connecter, avec un code à usage unique reçu par SMS. Le propriétaire principal ne peut pas être retiré." />
      {!owner && <div className="cx-note" style={{ marginBottom: '1rem' }}>Lecture seule : seul un propriétaire peut ajouter, modifier ou retirer un accès.</div>}
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <div className="cx-stack">
        {owner && <Invite roles={data.roles} onDone={reload} />}
        <Card title="Personnes autorisées">
          {data.operators.length === 0 ? <Empty>Aucun accès.</Empty> : (
            <ul className="cx-list">{data.operators.map((o) => (
              <li key={o.id} style={{ flexWrap: 'wrap' }}>
                <div className="grow" style={{ minWidth: 220 }}>
                  <div className="title">{o.name || 'Sans nom'} <span className="cx-mono cx-muted">{o.phone}</span> {o.is_primary && <Badge tone="warn">Propriétaire principal</Badge>} {o.is_me && <Badge tone="info">vous</Badge>}</div>
                  <div className="meta">
                    <Status tone={STATUS[o.status][0]}>{STATUS[o.status][1]}</Status>
                    <span>{o.role_label}</span>
                    {o.last_login_at && <span>dernière connexion {fmtRel(o.last_login_at)}</span>}
                    {o.sessions > 0 && <span>{o.sessions} session(s) ouverte(s)</span>}
                    {o.invited_by && <span>ajouté par {o.invited_by} le {fmtDate(o.created_at, false)}</span>}
                    {o.revoked_at && <span>retiré le {fmtDate(o.revoked_at)}</span>}
                    <span>{o.alerts_enabled ? (o.has_member_account ? 'reçoit les alertes' : 'alertes : pas de compte membre avec ce numéro') : 'ne reçoit pas les alertes'}</span>
                  </div>
                </div>
                {owner && o.status !== 'revoked' && (
                  <div className="cx-head-actions">
                    <select className="cx-select" style={{ width: 'auto' }} aria-label={`Rôle de ${o.name ?? o.phone}`} value={o.role} disabled={o.is_primary}
                      onChange={(e) => patch(o, { role: e.target.value as ConsoleRole })}>
                      {data.roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
                    </select>
                    <label className="cx-check"><input type="checkbox" checked={o.alerts_enabled} onChange={(e) => patch(o, { alerts_enabled: e.target.checked })} />Alertes</label>
                    {o.sessions > 0 && <button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={() => setConfirm({ op: o, kind: 'sessions' })}>Fermer les sessions</button>}
                    {!o.is_primary && <button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={() => setConfirm({ op: o, kind: 'revoke' })}>Retirer l'accès</button>}
                  </div>
                )}
              </li>
            ))}</ul>
          )}
        </Card>
        <Card title="Rôles">
          <dl className="cx-kv">{data.roles.map((r) => <div key={r.key}><dt>{r.label}</dt><dd>{r.help}</dd></div>)}</dl>
          <p className="cx-help mt">Les droits sont vérifiés par le serveur à chaque action : masquer un bouton n'est jamais la seule protection.</p>
        </Card>
      </div>}
      {confirm && (
        <Confirm title={confirm.kind === 'revoke' ? "Retirer l'accès" : 'Fermer les sessions'} danger={confirm.kind === 'revoke'}
          confirmLabel={confirm.kind === 'revoke' ? "Retirer l'accès" : 'Fermer les sessions'} onClose={() => setConfirm(null)}
          onConfirm={async () => {
            if (confirm.kind === 'revoke') await capi(`/access/${confirm.op.id}`, { method: 'DELETE' })
            else await capi(`/access/${confirm.op.id}/revoke-sessions`, { method: 'POST' })
            reload()
          }}>
          <p>{confirm.kind === 'revoke'
            ? `${confirm.op.name ?? confirm.op.phone} ne pourra plus se connecter à la console ; ses sessions ouvertes sont fermées immédiatement.`
            : `Les sessions de la console de ${confirm.op.name ?? confirm.op.phone} sont fermées${confirm.op.is_me ? ' (sauf celle-ci)' : ''}.`}</p>
        </Confirm>
      )}
    </>
  )
}

function Invite({ roles, onDone }: { roles: Data['roles']; onDone: () => void }) {
  const [country, setCountry] = useState<Country>(DEFAULT_COUNTRY)
  const [phone, setPhone] = useState('')
  const [name, setName] = useState('')
  const [role, setRole] = useState<ConsoleRole>('viewer')
  const [alerts, setAlerts] = useState(true)
  const [busy, setBusy] = useState(false)
  const valid = phone.trim() !== '' && isValidPhoneNumber(phone, country.iso as CountryCode)

  const submit = async () => {
    setBusy(true)
    try {
      await capi('/access', { method: 'POST', body: { phone, country: country.iso, name: name || undefined, role, alerts_enabled: alerts } })
      setPhone(''); setName(''); onDone()
    } catch { /* message affiche */ } finally { setBusy(false) }
  }

  return (
    <Card title="Ajouter une personne">
      <div className="cx-filters" style={{ marginBottom: 0 }}>
        <div className="cx-field wide">
          <label htmlFor="inv-phone">Numéro de téléphone</label>
          <div className="phone-row">
            <CountrySelect value={country} onChange={setCountry} />
            <input id="inv-phone" className="cx-input" type="tel" value={phone} onChange={(e) => setPhone(new AsYouType(country.iso as CountryCode).input(e.target.value))} placeholder="418 123 4567" />
          </div>
        </div>
        <div className="cx-field"><label htmlFor="inv-name">Nom (facultatif)</label><input id="inv-name" className="cx-input" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} /></div>
        <div className="cx-field"><label htmlFor="inv-role">Rôle</label>
          <select id="inv-role" className="cx-select" value={role} onChange={(e) => setRole(e.target.value as ConsoleRole)}>{roles.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}</select></div>
        <label className="cx-check" style={{ alignSelf: 'center' }}><input type="checkbox" checked={alerts} onChange={(e) => setAlerts(e.target.checked)} />Recevoir les alertes</label>
        <button className="cx-btn cx-btn-primary" onClick={submit} disabled={busy || !valid}>{busy ? <span className="cx-spinner" /> : 'Ajouter'}</button>
      </div>
      <p className="cx-help mt">La personne se connecte ensuite sur l'adresse de cette console avec son numéro : aucun SMS d'invitation n'est envoyé, prévenez-la.</p>
    </Card>
  )
}
