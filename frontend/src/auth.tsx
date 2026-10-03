import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { capi, consoleToken, ConsoleError, SESSION_LOST } from './api'

export type ConsoleRole = 'owner' | 'admin' | 'viewer'

export interface Operator {
  id: number
  name: string | null
  label: string
  phone: string
  role: ConsoleRole
  role_label: string
  is_primary: boolean
  session_expires_at: string | null
}

export interface Abilities {
  view: boolean
  operate: boolean
  manage_users: boolean
  manage_access: boolean
  manage_retention: boolean
}

export interface ConsolePayload { operator: Operator; abilities: Abilities; platform?: { url: string } }

interface State {
  operator: Operator | null
  abilities: Abilities | null
  loading: boolean
  /** Adresse de la plateforme supervisee. */
  platformUrl: string | null
  login: (token: string, payload: ConsolePayload) => void
  logout: () => Promise<void>
  /** Au moins ce role ? (le serveur reverifie toujours) */
  can: (role: ConsoleRole) => boolean
}

const Ctx = createContext<State | null>(null)
const RANK: Record<ConsoleRole, number> = { viewer: 1, admin: 2, owner: 3 }

export function ConsoleAuthProvider({ children }: { children: ReactNode }) {
  const [operator, setOperator] = useState<Operator | null>(null)
  const [abilities, setAbilities] = useState<Abilities | null>(null)
  const [loading, setLoading] = useState(true)
  const [platformUrl, setPlatformUrl] = useState<string | null>(null)

  const reset = useCallback(() => { setOperator(null); setAbilities(null) }, [])

  useEffect(() => {
    let alive = true
    if (!consoleToken.get()) { setLoading(false); return }
    capi<ConsolePayload>('/auth/me')
      .then((p) => { if (alive) { setOperator(p.operator); setAbilities(p.abilities); setPlatformUrl(p.platform?.url ?? null) } })
      .catch((err) => { if (err instanceof ConsoleError && err.status === 401) consoleToken.clear() })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [])

  useEffect(() => {
    const onLost = () => reset()
    window.addEventListener(SESSION_LOST, onLost)
    return () => window.removeEventListener(SESSION_LOST, onLost)
  }, [reset])

  const value: State = {
    operator, abilities, loading, platformUrl,
    login: (token, payload) => { consoleToken.set(token); setOperator(payload.operator); setAbilities(payload.abilities); setPlatformUrl(payload.platform?.url ?? null) },
    logout: async () => {
      try { await capi('/auth/logout', { method: 'POST', toast: false }) } catch { /* session deja fermee */ }
      consoleToken.clear()
      reset()
    },
    can: (role) => !!operator && RANK[operator.role] >= RANK[role],
  }
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useConsole(): State {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useConsole doit être utilisé dans ConsoleAuthProvider')
  return ctx
}
