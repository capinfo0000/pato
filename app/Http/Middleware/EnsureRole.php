<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ロール（guest / cast / admin）で入口を制限する。
 * Controller に `if ($user->role...)` を散らさないためのミドルウェア。
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);
        abort_unless(in_array($user->role, $roles, true), 403);

        return $next($request);
    }
}
