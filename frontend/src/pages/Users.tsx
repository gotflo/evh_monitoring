import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { qs } from '../api'
import { Card, Empty, ErrorState, Loading, PageHeader, Status } from '../ui'
import { fmtNum, fmtRel, useData, type Tone } from '../format'

interface Row { id: number; name: string | null; phone: string; matricule: string | null; tribe: string | null; roles: string[]; status: string; last_seen_at: string | null; last_login_at: string | null; created_at: string | null }
interface Data { total: number; page: number; per_page: number; users: Row[]; roles: { key: string; name: string }[] }

const USER_STATUS: Record<string, [Tone, string]> = { active: ['ok', 'Actif'], inactive: ['muted', 'Inactif'], blocked: ['crit', 'Bloqué'] }

export default function Users() {
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const f = Object.fromEntries(['q', 'status', 'role', 'sort', 'page'].map((k) => [k, params.get(k) ?? '']))
  const [q, setQ] = useState(f.q)
  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); if (k !== 'page') p.delete('page'); setParams(p) }
  const { data, error, loading, reload } = useData<Data>(`/users${qs(f)}`)

  // Recherche au fil de la saisie (apres une courte pause).
  useEffect(() => {
    if (q.trim() === f.q) return
    const t = window.setTimeout(() => set('q', q.trim()), 350)
    return () => window.clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q])

  const page = Number(f.page || 1)
  const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1

  return (
    <>
      <PageHeader title="Utilisateurs" sub="Comptes des membres : état, appartenance, sessions et actions d'administration." />
      <div className="cx-filters">
        <div className="cx-field wide"><label htmlFor="uq">Rechercher</label>
          <input id="uq" className="cx-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, numéro, matricule..." autoComplete="off" /></div>
        <div className="cx-field"><label htmlFor="us">État</label>
          <select id="us" className="cx-select" value={f.status} onChange={(e) => set('status', e.target.value)}>
            <option value="">Tous</option><option value="active">Actifs</option><option value="inactive">Inactifs</option><option value="blocked">Bloqués</option><option value="never">Jamais connectés</option>
          </select></div>
        <div className="cx-field"><label htmlFor="ur">Rôle</label>
          <select id="ur" className="cx-select" value={f.role} onChange={(e) => set('role', e.target.value)}>
            <option value="">Tous</option>{data?.roles.map((r) => <option key={r.key} value={r.key}>{r.name}</option>)}
          </select></div>
        <div className="cx-field"><label htmlFor="uso">Trier par</label>
          <select id="uso" className="cx-select" value={f.sort} onChange={(e) => set('sort', e.target.value)}>
            <option value="">Dernière utilisation</option><option value="recent">Inscription récente</option><option value="name">Nom</option>
          </select></div>
      </div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <Card title={`${fmtNum(data.total)} compte${data.total > 1 ? 's' : ''}`}>
          {data.users.length === 0 ? <Empty>Aucun compte ne correspond.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table responsive">
              <thead><tr><th>Nom</th><th>Téléphone</th><th>Tribu</th><th>Rôles</th><th>État</th><th>Dernière utilisation</th></tr></thead>
              <tbody>{data.users.map((u) => (
                <tr key={u.id} className="clickable" tabIndex={0} onClick={() => navigate(`/utilisateurs/${u.id}`)} onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/utilisateurs/${u.id}`) }}>
                  <td data-label="Nom"><strong>{u.name ?? <span className="cx-muted">Profil non rempli</span>}</strong>{u.matricule && <div className="cx-help">{u.matricule}</div>}</td>
                  <td data-label="Téléphone" className="cx-mono">{u.phone}</td>
                  <td data-label="Tribu">{u.tribe ?? '-'}</td>
                  <td data-label="Rôles">{u.roles.join(', ') || '-'}</td>
                  <td data-label="État"><Status tone={USER_STATUS[u.status]?.[0] ?? 'muted'}>{USER_STATUS[u.status]?.[1] ?? u.status}</Status></td>
                  <td data-label="Dernière utilisation">{fmtRel(u.last_seen_at ?? u.last_login_at)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
          {pages > 1 && (
            <div className="cx-pager">
              <button className="cx-btn cx-btn-ghost cx-btn-sm" disabled={page <= 1} onClick={() => set('page', String(page - 1))}>Précédent</button>
              <span>Page {page} sur {pages}</span>
              <button className="cx-btn cx-btn-ghost cx-btn-sm" disabled={page >= pages} onClick={() => set('page', String(page + 1))}>Suivant</button>
            </div>
          )}
        </Card>
      )}
    </>
  )
}
