<?php

declare(strict_types=1);

/**
 * 模式匹配层：本次要补的那一层。现在方法体全抛错。
 *
 * 契约见 README「对外契约」1~9 与「本次要补的一层」。
 * 只依赖 PHP 8 标准库；判据完全确定，不读时间、不用随机源、不依赖数组 hash 顺序。
 */
final class Matcher
{
    /**
     * 用**整张表**去匹配一条请求路径。
     *
     * 命中返回 `['pattern' => 原样模式, 'method' => 大写方法, 'handler' => …, 'params' => 捕获表]`；
     * 不命中返回 `null`。
     *
     * @return array{pattern:string,method:string,handler:mixed,params:array<string,string>}|null
     */
    public static function match(RouteTable $table, string $method, string $path): ?array
    {
        throw new \LogicException('not implemented');
    }

    /**
     * 把一条模式编译成分段表示；非法模式抛 `RouteException`。
     *
     * 每段形如 `['kind' => 'static'|'dynamic'|'optional'|'wildcard', 'name' => string]`。
     *
     * @return array{raw:string,segments:list<array{kind:string,name:string}>}
     */
    public static function compile(string $pattern): array
    {
        throw new \LogicException('not implemented');
    }

    /**
     * 用编译结果匹配一条**已归一化**路径的分段；不匹配返回 `null`，命中返回捕获表。
     *
     * @param list<string> $segments
     *
     * @return array<string,string>|null
     */
    public static function capture(array $compiled, array $segments): ?array
    {
        throw new \LogicException('not implemented');
    }
}
