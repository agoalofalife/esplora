<?php

declare(strict_types=1);

namespace Esplora\Tracker\Middleware;

use Closure;
use Esplora\Tracker\Esplora;
use Esplora\Tracker\Models\Visitor;
use Illuminate\Http\Request;

class Tracking
{
    /**
     * @var Esplora
     */
    protected Esplora $esplora;

    /**
     * Tracking constructor.
     *
     * @param Esplora $esplora
     */
    public function __construct(Esplora $esplora)
    {
        $this->esplora = $esplora;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $this->boot($request);

        return $next($request);
    }

    /**
     * @param Request $request
     */
    protected function boot(Request $request): void
    {
        if (! $this->esplora->isNeedVisitWrite($request)) {
            return;
        }

        $this->esplora->saveAfterResponse(new Visitor([
            'id'                 => $this->esplora->loadVisitId(),
            'ip'                 => $request->ip(),
            'referer'            => $request->headers->get('referer'),
            'user_agent'         => $request->userAgent(),
            'url'                => $request->fullUrl(),
            'preferred_language' => $request->getPreferredLanguage(),
            'created_at'         => now(),
        ]));
    }
}
