<?php

declare(strict_types=1);

namespace Matrix\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    protected string $secret;

    protected string $algorithm;

    public function __construct(string $secret, string $algorithm = 'HS256')
    {
        $this->secret    = $secret;
        $this->algorithm = $algorithm;
    }

    /**
     * JWT 鉴权中间件：从 Authorization Bearer Header 中验证 Token。
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return new JsonResponse([
                'code'    => 401,
                'message' => 'Missing or invalid Authorization header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = substr($header, 7);

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // 将解码后的用户数据挂载到 Request
            $request->attributes->set('auth_user', (array) $decoded);

        } catch (\Exception $e) {
            return new JsonResponse([
                'code'    => 401,
                'message' => 'Invalid token: ' . $e->getMessage(),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
