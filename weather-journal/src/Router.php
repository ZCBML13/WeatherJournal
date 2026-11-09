<?php

declare(strict_types=1);

/**
 * A tiny router: routes like "/api/cities/{id}" become regular expressions,
 * and the named parts are passed to the handler as $params.
 */
final class Router
{
    /** @var list<array{string, string, callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        try {
            $allowed = [];
            foreach ($this->routes as [$method, $regex, $handler]) {
                if (!preg_match($regex, $request->path, $matches)) {
                    continue;
                }
                if ($method !== $request->method) {
                    $allowed[] = $method;
                    continue;
                }
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $handler($request, $params);
            }

            if ($allowed) {
                throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', array_unique($allowed))]);
            }
            throw new HttpException(404, 'Not found');
        } catch (HttpException $e) {
            return Response::fromException($e);
        } catch (Throwable $e) {
            error_log((string) $e);

            return new Response(500, ['error' => 'Internal server error']);
        }
    }
}
