import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { capi } from '../api'
import { useConsole } from '../auth'
import { Badge, Card, CIcon, Confirm, Empty, ErrorState, Loading, Outcome, PageHeader, Status } from '../ui'
import { fmtDate, fmtMs, fmtNum, fmtRel, LEVEL_LABEL, LEVEL_TONE, useData, type Tone } from '../format'

interface Detail {
  user: {
    id: number; phone: string; name: string | null; photo_url: string | null; matricule: string | null; created_at: string | null
    phone_verified_at: string | null; last_login_at: string | null; last_seen_at: string | null; activity_status: string
    activity_override: string | null; blocked_at: string | null; blocked_reason: string | null; profile_completed: boolean; is_console_operator: boolean
  }
  belonging: { tribe: string | null; gem: string | null; departments: string[]; led_departments: string[]; roles: { key: string; name: string; scope_kind: string | null }[] }
  sessions: { count: number; last_used_at: string | null; oldest_at: string | null }
  devices: { id: number; device: string; last_used_at: string | null; last_received_at: string | null; error: string | null; created_at: string | null }[]
  usage: { active_days_30: number }
  related: { label: string; count: number }[]
  related_error: string | null
  audit: { id: number; label: string; action: string; as: string; created_at: string }[]
  events: { id: number; created_at: string; level: string; service: string; type: string; label: string; outcome: string | null; message: string; request_id: string | null }[]
  logins: { id: number; created_at: string; ip: string; location: string | null; country_code: string | null }[]
  localities: { label: string; count: number; last_at: string; country_code: string | null }[]
  home_countries: string[]
  geo_enabled: boolean
  requests: { id: number; created_at_iso: string; reason: string; method: string; route: string; status: number; duration_ms: number; request_id: string }[]
}
interface Impact { deleted: { label: string; count: number }[]; kept: { label: string; count: number }[]; released: { label: string; count: number }[]; warnings: string[]; name: string | null; phone: string }

const STATUS: Record<string, [Tone, string]> = { active: ['ok', 'Actif'], inactive: ['muted', 'Inactif'] }

export default function UserDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = useConsole()
  const { data, error, loading, reload } = useData<Detail>(`/users/${id}`, 30000)
  const [dialog, setDialog] = useState<'block' | 'unblock' | 'sessions' | 'delete' | null>(null)
  const [reason, setReason] = useState('')
  const [impact, setImpact] = useState<Impact | null>(null)
  const [confirmText, setConfirmText] = useState('')

  if (error) return <><Back /><ErrorState message={error} onRetry={reload} /></>
  if (!data) return <><Back />{loading && <Loading />}</>
  const u = data.user

  const openDelete = async () => {
    setConfirmText(''); setImpact(null); setDialog('delete')
    try { setImpact(await capi<Impact>(`/users/${u.id}/impact`)) } catch { setDialog(null) }
  }

  return (
    <>
      <Back />
      <PageHeader
        title={u.name ?? `Compte #${u.id}`}
        sub={<><span className="cx-mono">{u.phone}</span>{u.matricule && ` - ${u.matricule}`} - inscrit le {fmtDate(u.created_at, false)}</>}
        actions={can('admin') && (
          <>
            {u.blocked_at
              ? <button className="cx-btn cx-btn-primary" onClick={() => setDialog('unblock')}>Débloquer</button>
              : <button className="cx-btn cx-btn-ghost" onClick={() => { setReason(''); setDialog('block') }}>Bloquer</button>}
            <button className="cx-btn cx-btn-ghost" onClick={() => setDialog('sessions')} disabled={!data.sessions.count}>Fermer les sessions</button>
            <button className="cx-btn cx-btn-danger" onClick={openDelete}>Supprimer...</button>
          </>
        )}
      />

      {u.blocked_at && (
        <div className="cx-banner down" role="status">
          <div className="icon"><CIcon name="x" /></div>
          <div className="grow"><strong>Compte bloqué depuis le {fmtDate(u.blocked_at)}</strong><span>Motif : {u.blocked_reason}</span></div>
        </div>
      )}

      <div className="cx-grid two">
        <Card title="État du compte">
          <dl className="cx-kv">
            <div><dt>Statut d'activité</dt><dd><Status tone={STATUS[u.activity_status]?.[0] ?? 'muted'}>{STATUS[u.activity_status]?.[1] ?? u.activity_status}</Status>{u.activity_override && <span className="cx-help"> (forcé manuellement)</span>}</dd></div>
            <div><dt>Dernière connexion</dt><dd>{fmtDate(u.last_login_at)}</dd></div>
            <div><dt>Dernière utilisation</dt><dd>{fmtRel(u.last_seen_at)}</dd></div>
            <div><dt>Jours actifs (30 j)</dt><dd>{fmtNum(data.usage.active_days_30)}</dd></div>
            <div><dt>Profil</dt><dd>{u.profile_completed ? 'Complété' : 'Incomplet'}</dd></div>
            <div><dt>Numéro vérifié</dt><dd>{fmtDate(u.phone_verified_at)}</dd></div>
            {u.is_console_operator && <div><dt>Console</dt><dd><Badge tone="info">A aussi accès à la console</Badge></dd></div>}
          </dl>
        </Card>
        <Card title="Appartenance et rôles">
          <dl className="cx-kv">
            <div><dt>Tribu</dt><dd>{data.belonging.tribe ?? '-'}</dd></div>
            <div><dt>GEM</dt><dd>{data.belonging.gem ?? '-'}</dd></div>
            <div><dt>Départements</dt><dd>{data.belonging.departments.join(', ') || '-'}</dd></div>
            {data.belonging.led_departments.length > 0 && <div><dt>Responsable de</dt><dd>{data.belonging.led_departments.join(', ')}</dd></div>}
            <div><dt>Rôles</dt><dd>{data.belonging.roles.map((r) => <Badge key={r.key + r.scope_kind}>{r.name}</Badge>)}{data.belonging.roles.length === 0 && '-'}</dd></div>
          </dl>
        </Card>
      </div>

      <div className="cx-grid two mt">
        <Card title="Localités de connexion" actions={<span className="cx-help">d'après l'adresse IP, approximatif</span>}>
          {data.localities.length === 0 ? <Empty>Aucune connexion enregistrée depuis la mise en service de la supervision.</Empty> : (
            <ul className="cx-list">{data.localities.map((l) => {
              const unusual = !!l.country_code && !data.home_countries.includes(l.country_code)
              return (
                <li key={l.label}>{unusual ? <Status tone="warn">Inhabituel</Status> : <Status tone="ok">Habituel</Status>}
                  <div className="grow"><div className="title">{l.label}</div><div className="meta"><span>{fmtNum(l.count)} connexion(s)</span><span>dernière {fmtRel(l.last_at)}</span></div></div></li>
              )
            })}</ul>
          )}
          {!data.geo_enabled && <p className="cx-help mt">Localisation désactivée (GEOIP_PROVIDER=none).</p>}
        </Card>
        <Card title="Dernières connexions">
          {data.logins.length === 0 ? <Empty>Aucune connexion enregistrée.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table compact">
              <thead><tr><th>Date</th><th>Localité</th><th>Adresse IP</th></tr></thead>
              <tbody>{data.logins.map((l) => (
                <tr key={l.id}><td>{fmtDate(l.created_at)}</td><td>{l.location ?? '-'}{l.country_code && !data.home_countries.includes(l.country_code) && <> <Badge tone="warn">pays inhabituel</Badge></>}</td><td className="cx-mono">{l.ip}</td></tr>
              ))}</tbody>
            </table></div>
          )}
        </Card>
      </div>

      <div className="cx-grid two mt">
        <Card title="Sessions et appareils">
          <p style={{ fontSize: '0.88rem' }}>{data.sessions.count ? <>{data.sessions.count} session(s) ouverte(s), dernière utilisation {fmtRel(data.sessions.last_used_at)}.</> : 'Aucune session ouverte.'}</p>
          {data.devices.length === 0 ? <p className="cx-help mt">Aucun appareil abonné aux notifications push.</p> : (
            <ul className="cx-list mt">{data.devices.map((d) => (
              <li key={d.id}><div className="grow"><div className="title">{d.device}</div>
                <div className="meta"><span>abonné le {fmtDate(d.created_at, false)}</span><span>dernier envoi {fmtRel(d.last_used_at)}</span><span>dernier accusé de réception {fmtRel(d.last_received_at)}</span>{d.error && <span>erreur : {d.error}</span>}</div></div></li>
            ))}</ul>
          )}
        </Card>
        <Card title="Éléments liés" actions={<span className="cx-help">nombres seulement, jamais le contenu</span>}>
          {data.related_error ? <p className="cx-note warn">{data.related_error}</p> : data.related.length === 0 ? <Empty>Aucun élément lié.</Empty> : (
            <table className="cx-table compact"><tbody>{data.related.map((r) => <tr key={r.label}><td>{r.label}</td><td className="num">{fmtNum(r.count)}</td></tr>)}</tbody></table>
          )}
        </Card>
      </div>

      <div className="cx-grid two mt">
        <Card title="Actions récentes (journal de l'église)" actions={<Link className="cx-help" to={`/activite?user_id=${u.id}&period=90d`}>Toute l'activité</Link>}>
          {data.audit.length === 0 ? <Empty>Aucune action enregistrée.</Empty> : (
            <ul className="cx-list">{data.audit.map((a) => (
              <li key={a.id}><div className="grow"><div className="title">{a.label}</div><div className="meta"><span>{fmtDate(a.created_at)}</span><span>en tant que {a.as}</span></div></div></li>
            ))}</ul>
          )}
        </Card>
        <Card title="Connexions et événements techniques" actions={<Link className="cx-help" to={`/journaux?user_id=${u.id}&period=90d`}>Journal</Link>}>
          {data.events.length === 0 ? <Empty>Aucun événement.</Empty> : (
            <ul className="cx-list">{data.events.map((e) => (
              <li key={e.id}><Status tone={LEVEL_TONE[e.level] ?? 'muted'}>{LEVEL_LABEL[e.level] ?? e.level}</Status>
                <div className="grow"><Link className="title" to={`/journaux?event=${e.id}`}>{e.label}</Link>
                  <div className="meta"><span>{fmtDate(e.created_at)}</span><Outcome value={e.outcome} /></div></div></li>
            ))}</ul>
          )}
          {data.requests.length > 0 && <>
            <h3 style={{ fontSize: '0.88rem', margin: '0.8rem 0 0.3rem' }}>Requêtes notables</h3>
            <ul className="cx-list">{data.requests.map((r) => (
              <li key={r.id}><div className="grow"><div className="title cx-mono">{r.method} {r.route} : HTTP {r.status}</div>
                <div className="meta"><span>{fmtDate(r.created_at_iso)}</span><span>{fmtMs(r.duration_ms)}</span><Link to={`/journaux?request_id=${r.request_id}`}>{r.request_id}</Link></div></div></li>
            ))}</ul>
          </>}
        </Card>
      </div>

      {dialog === 'block' && (
        <Confirm title="Bloquer ce compte" confirmLabel="Bloquer le compte" danger disabled={reason.trim().length < 3}
          onClose={() => setDialog(null)} onConfirm={async () => { await capi(`/users/${u.id}/block`, { method: 'POST', body: { reason } }); reload() }}>
          <p>La personne est déconnectée de tous ses appareils, ne reçoit plus de notifications et ne peut plus demander de code de connexion. Ses données sont conservées.</p>
          <div className="cx-field"><label htmlFor="br">Motif (consigné dans l'audit)</label>
            <textarea id="br" className="cx-textarea" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} /></div>
        </Confirm>
      )}
      {dialog === 'unblock' && (
        <Confirm title="Débloquer ce compte" confirmLabel="Débloquer" onClose={() => setDialog(null)}
          onConfirm={async () => { await capi(`/users/${u.id}/unblock`, { method: 'POST' }); reload() }}>
          <p>La personne pourra de nouveau se connecter avec son numéro et un code reçu par SMS.</p>
        </Confirm>
      )}
      {dialog === 'sessions' && (
        <Confirm title="Fermer toutes les sessions" confirmLabel="Fermer les sessions" onClose={() => setDialog(null)}
          onConfirm={async () => { await capi(`/users/${u.id}/revoke-sessions`, { method: 'POST' }); reload() }}>
          <p>La personne devra se reconnecter (code par SMS) sur chacun de ses appareils. Utile en cas de téléphone perdu ou partagé.</p>
        </Confirm>
      )}
      {dialog === 'delete' && (
        <Confirm title="Supprimer définitivement ce compte" confirmLabel="Supprimer définitivement" danger
          disabled={!impact || confirmText.trim().toUpperCase() !== 'SUPPRIMER'} onClose={() => setDialog(null)}
          onConfirm={async () => { await capi(`/users/${u.id}`, { method: 'DELETE', body: { confirmation: confirmText } }); navigate('/utilisateurs', { replace: true }) }}>
          {!impact ? <Loading label="Calcul des conséquences..." /> : <>
            {impact.warnings.map((w) => <div key={w} className="cx-note warn">{w}</div>)}
            <ImpactList title="Supprimé avec le compte" items={impact.deleted} empty="Aucune donnée liée." />
            <ImpactList title="Conservé pour les autres (auteur effacé)" items={impact.kept} empty="Rien." />
            {impact.released.length > 0 && <ImpactList title="Fonctions libérées" items={impact.released} empty="" />}
            <div className="cx-field"><label htmlFor="dc">Pour confirmer, tapez <strong>SUPPRIMER</strong></label>
              <input id="dc" className="cx-input" value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoComplete="off" /></div>
          </>}
        </Confirm>
      )}
    </>
  )
}

function ImpactList({ title, items, empty }: { title: string; items: { label: string; count: number }[]; empty: string }) {
  return (
    <div>
      <h3 style={{ fontSize: '0.88rem', marginBottom: '0.25rem' }}>{title}</h3>
      {items.length === 0 ? <p className="cx-help">{empty}</p> : (
        <table className="cx-table compact"><tbody>{items.map((i) => <tr key={i.label}><td>{i.label}</td><td className="num">{fmtNum(i.count)}</td></tr>)}</tbody></table>
      )}
    </div>
  )
}

function Back() {
  return <Link to="/utilisateurs" className="cx-help" style={{ display: 'inline-flex', gap: '0.3rem', alignItems: 'center', marginBottom: '0.6rem' }}><CIcon name="back" size={14} />Utilisateurs</Link>
}
