<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    public function __construct(
        private readonly ViewFactory $view,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->view->share('appearance', $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
