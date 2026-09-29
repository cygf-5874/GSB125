<?php

declare(strict_types=1);

/**
 * routepat 既有用例：13 个。**别改这个文件。**
 *
 * 用法： php tests/run.php     全绿 → 退出码 0，否则 1
 *
 * 只覆盖当前已经可用的那一层：注册、请求路径归一化、静态段的精确匹配。
 * 不涉及动态段 / 可选段 / 尾部通配 / 优先级 / 百分号解码（那是 `Matcher` 的事）。
 */

require __DIR__ . '/../autoload.php';

$GLOBALS['t_total'] = 0;
$GLOBALS['t_pass'] = 0;

function tcase(string $name, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
    } catch (\Throwable $exc) {
        printf("FAIL %s —— %s: %s\n", $name, get_class($exc), $exc->getMessage());

        return;
    }

    $GLOBALS['t_pass']++;
}

function same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            $what . ' 期望=' . var_export($want, true) . ' 实际=' . var_export($got, true)
        );
    }
}

/** 取一次静态命中的 handler；没命中直接抛，便于定位。 */
function hit(RouteTable $t, string $method, string $path): mixed
{
    $m = $t->match($method, $path);

    if ($m === null) {
        throw new \RuntimeException($method . ' ' . $path . ' 期望命中，实际 null');
    }

    return $m['handler'];
}

// ---------------------------------------------------------------------------
// 1~2 注册
// ---------------------------------------------------------------------------

tcase('count() 与注册条数一致', static function (): void {
    $t = new RouteTable();
    same(0, $t->count(), '空表');
    $t->add('GET', '/a');
    $t->add('GET', '/b');
    $t->add('POST', '/b');
    same(3, $t->count(), '三次注册后');
});

tcase('routes() 保持注册顺序', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/z');
    $t->add('POST', '/a');
    $t->add('PUT', '/m');

    same(['GET', 'POST', 'PUT'], array_column($t->routes(), 'method'), 'method 顺序');
    same(['/z', '/a', '/m'], array_column($t->routes(), 'pattern'), 'pattern 顺序');
});

// ---------------------------------------------------------------------------
// 3~6 静态精确匹配
// ---------------------------------------------------------------------------

tcase('静态路径精确命中，模式原样带回', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/users', 'list');

    $m = $t->match('GET', '/users');
    same(true, $m !== null, '应命中');
    same('list', $m['handler'], 'handler');
    same('/users', $m['pattern'], 'pattern 原样');
    same('GET', $m['method'], 'method 大写');
});

tcase('method 不匹配返回 null', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/users', 'list');

    same(null, $t->match('POST', '/users'), '未注册的方法');
    same(null, $t->match('DELETE', '/users'), '另一个未注册的方法');
});

tcase('method 大小写不敏感', static function (): void {
    $t = new RouteTable();
    $t->add('get', '/users', 'list');

    same('list', hit($t, 'GET', '/users'), '注册小写、查询大写');
    same('list', hit($t, 'get', '/users'), '注册小写、查询小写');
    same('GET', $t->match('GET', '/users')['method'], '返回的 method 统一大写');
});

tcase('未注册的路径返回 null', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/users', 'list');

    same(null, $t->match('GET', '/nope'), '未注册路径');
    same(null, $t->match('GET', '/users/1'), '更深一层也未注册');
});

// ---------------------------------------------------------------------------
// 7~9 请求路径归一化
// ---------------------------------------------------------------------------

tcase('查询串不参与匹配', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/search', 's');

    same('s', hit($t, 'GET', '/search?q=1&r=2'), '带查询串');
    same('s', hit($t, 'GET', '/search?'), '只有问号');
});

tcase('连续斜杠折叠', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/a/b', 'ab');

    same('ab', hit($t, 'GET', '//a///b'), '多余斜杠');
});

tcase('末尾斜杠去除', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/a/b', 'ab');

    same('ab', hit($t, 'GET', '/a/b/'), '请求侧末尾斜杠');
    same('ab', hit($t, 'GET', '//a/b//'), '折叠 + 末尾斜杠');
});

// ---------------------------------------------------------------------------
// 10~12 根路径与 params
// ---------------------------------------------------------------------------

tcase('空路径等价于根', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/', 'root');
    $t->add('GET', '/a', 'a');

    same('root', hit($t, 'GET', ''), '空串');
    same('root', hit($t, 'GET', '/'), '斜杠');
    same('root', hit($t, 'GET', '//'), '双斜杠');
});

tcase('注册为空路径或斜杠的路由都能命中根', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '', 'empty');

    same('empty', hit($t, 'GET', '/'), '注册空串命中 /');
    same(null, (new RouteTable())->match('GET', '/'), '空表返回 null');
});

tcase('静态命中时没有捕获', static function (): void {
    $t = new RouteTable();
    $t->add('GET', '/a', 'a');

    same([], $t->match('GET', '/a')['params'], '静态路由的 params 是空数组');
});

// ---------------------------------------------------------------------------
// 13 normalizePath 可以直接调用
// ---------------------------------------------------------------------------

tcase('normalizePath 是公开的纯函数', static function (): void {
    same('/a/b', RouteTable::normalizePath('//a//b/?x=1'), '折叠 + 尾部斜杠 + 查询串');
    same('/', RouteTable::normalizePath(''), '空串 -> 根');
    same('/', RouteTable::normalizePath('///'), '全是斜杠 -> 根');
    same('/a', RouteTable::normalizePath('a'), '缺前导斜杠补齐');
    same('/a/b', RouteTable::normalizePath('/a/b'), '原样返回');
    same('/a/b', RouteTable::normalizePath('/a/b'), '多次调用结果相同');
});

// ---------------------------------------------------------------------------

printf("通过 %d/%d\n", $GLOBALS['t_pass'], $GLOBALS['t_total']);
exit($GLOBALS['t_pass'] === $GLOBALS['t_total'] ? 0 : 1);
