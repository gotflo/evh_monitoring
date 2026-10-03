<?php

namespace App\Http\Middleware;

use App\Models\Operator;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controle d'acces de la console, verifie a CHAQUE requete cote serveur :
 *  - jeton de session de la console (garde « console ») d'une personne toujours autorisee ;
 *  - role minimal exige par la route (viewer < admin < owner).
 * Usage : ->middleware('console:admin'). Un refus de droits est consigne dans l'audit.
 */
class ConsoleAccess
{
    public function handle(Request $request, Closure $next, string $role = 'viewer'): Response
    {
        $operator = $request->user('console');
        if (! $operator instanceof Operator) {
            abort(401, 'Session de la console expirée. Reconnectez-vous.');
        }
        // Acces retire depuis : la session (meme recente) ne vaut plus rien.
        $operator->refresh();
        if (! $operator->isAllowed()) {
            $operator->tokens()->delete();
            abort(401, 'Votre accès à la console a été retiré.');
        }
        if (! $operator->atLeast($role)) {
            Audit::log($operator, 'console.denied', 'denied', 'route', null, $request->route()?->uri(), [
                'method' => $request->getMethod(), 'required_role' => $role, 'role' => $operator->role,
            ]);
            abort(403, 'Votre rôle ne permet pas cette action.');
        }

        return $next($request);
    }
}
