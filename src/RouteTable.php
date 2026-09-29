<?php

declare(strict_types=1);

/**
 * 路由表：注册 + 请求路径归一化 + **只认静态段**的精确匹配。
 *
 * 这一层是既有实现，已经可用；完整的模式匹配（动态段 / 可选段 / 尾部通配 /
 * 优先级 / 百分号解码）在 `Matcher`。
 *
 * 方法签名与语义见 README「对外契约」1、7、8。
 */
final class RouteTable
{
    /** @var list<array{method:string,pattern:string,handler:mixed}> 注册顺序 */
    private array $routes = [];

    /**
     * 注册一条路由。`$method` 大小写不敏感（内部统一大写）。
     * `$pattern` 原样保存；同一个 `$pattern` 允许重复注册（先到先得）。
     */
    public function add(string $method, string $pattern, mixed $handler = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * 注册顺序的路由列表（只读副本）。
     *
     * @return list<array{method:string,pattern:string,handler:mixed}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /** 已注册的路由条数。 */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * **请求路径**归一化：剥掉查询串（第一个 `?` 起）、折叠连续 `/`、
     * 去掉末尾 `/`（根 `/` 除外）；空串等价于 `/`。
     *
     * 只对请求路径用；模式串不要丢进来 —— 模式里的 `{name?}` 自带问号。
     */
    public static function normalizePath(string $path): string
    {
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                return '/';
            }
        }

        return $path;
    }

    /**
     * 既有匹配：只认**静态段**——注册的模式里含 `{` 的一律跳过（交给 `Matcher`）。
     * 请求路径先过 `normalizePath()`；`$method` 大小写不敏感。
     *
     * 命中返回 `['pattern' => 原样模式, 'method' => 大写方法, 'handler' => …, 'params' => []]`，
     * 否则返回 `null`。
     *
     * @return array{pattern:string,method:string,handler:mixed,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = self::normalizePath($path);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (strpos($route['pattern'], '{') !== false) {
                continue;
            }

            if (self::normalizePath($route['pattern']) === $path) {
                return [
                    'pattern' => $route['pattern'],
                    'method' => $route['method'],
                    'handler' => $route['handler'],
                    'params' => [],
                ];
            }
        }

        return null;
    }
}
