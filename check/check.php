<?php

declare(strict_types=1);

/**
 * 固定验收入口：routepat 的「对外契约」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                        跑全部 9 个场景，全过才退 0
 *   php check/check.php -list                  列出全部场景
 *   php check/check.php --only dynamic         只跑一组
 *   php check/check.php --only dynamic,priority 只跑指定的几组
 *
 * 五组共 9 个场景：
 *   static   2 —— 既有静态精确匹配与请求路径归一化（走 RouteTable，既有能力不得回归）
 *   dynamic  2 —— `{name}` 捕获一段 / 同名段值必须相等
 *   optional 2 —— 末尾可选段可缺省；中间出现可选段或通配 → RouteException
 *   wildcard 1 —— 末尾 `{name:path*}` 吃掉剩余（含 `/`），可以为空
 *   priority 2 —— 同优先级的静态基线（注册顺序、纯函数）；静态 > 动态 > 可选 > 通配
 *
 * 只依赖 PHP 8 标准库。判据完全确定：不读时间、不用随机源、不依赖数组 hash 顺序。
 * 失败不早退；每个场景用 try/catch(\Throwable) 兜住后继续。
 */

require __DIR__ . '/../autoload.php';

// ---------------------------------------------------------------------------
// 断言失败
// ---------------------------------------------------------------------------

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function show(mixed $v): string
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_string($v)) {
        return "'" . $v . "'";
    }
    if ($v === null) {
        return 'null';
    }
    if (is_array($v)) {
        $enc = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $enc === false ? '(array)' : $enc;
    }

    return (string) $v;
}

function expect_same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, show($want), show($got));
    }
}

/** 捕获表比较：键顺序不参与判据（先按字节序排序再逐对比较）。 */
function expect_params(array $want, mixed $got, string $what): void
{
    if (!is_array($got)) {
        throw new CheckFailure($what, show($want), show($got));
    }

    $a = $want;
    $b = $got;
    ksort($a);
    ksort($b);

    if ($a !== $b) {
        throw new CheckFailure($what, show($want), show($got));
    }
}

function expect_route_exception(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (RouteException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 RouteException',
            'RouteException',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 RouteException', 'RouteException', '没有抛异常');
}

/** 走既有 RouteTable::match 命中一次；没命中直接判失败。 */
function rt_hit(RouteTable $t, string $method, string $path, string $what): array
{
    $m = $t->match($method, $path);

    if ($m === null) {
        throw new CheckFailure($what, '命中', 'null');
    }

    return $m;
}

/** 走 Matcher::match 命中一次；没命中直接判失败。 */
function mt_hit(RouteTable $t, string $method, string $path, string $what): array
{
    $m = Matcher::match($t, $method, $path);

    if ($m === null) {
        throw new CheckFailure($what, '命中', 'null');
    }

    return $m;
}

function table(array $routes): RouteTable
{
    $t = new RouteTable();
    foreach ($routes as [$method, $pattern, $handler]) {
        $t->add($method, $pattern, $handler);
    }

    return $t;
}

// ---------------------------------------------------------------------------
// [static] 2 —— 既有能力：静态精确匹配 + 请求路径归一化（走 RouteTable::match）
// ---------------------------------------------------------------------------

$static = [
    ['exact', '静态段精确匹配、method 不匹配返回 null、归一化不影响结果', function (): void {
        $t = table([
            ['GET', '/users', 'list'],
            ['POST', '/users', 'create'],
            ['GET', '/users/{id}', 'show'],
        ]);

        expect_same('list', rt_hit($t, 'GET', '/users', 'GET /users 应命中 list')['handler'], '/users -> list');
        expect_same('create', rt_hit($t, 'POST', '/users', 'POST /users 应命中 create')['handler'], '/users -> create');
        expect_same('list', rt_hit($t, 'get', '//users/?q=1', '大小写 + 归一化后应命中 list')['handler'], '归一化命中');
        expect_same('/users', rt_hit($t, 'GET', '/users', 'pattern 应原样带回')['pattern'], 'pattern');
        expect_params([], rt_hit($t, 'GET', '/users', '静态命中无捕获')['params'], '静态命中 params 为空');
        expect_same(null, $t->match('DELETE', '/users'), 'method 不匹配返回 null');
        expect_same(null, $t->match('GET', '/nope'), '未注册路径返回 null');
    }],

    ['root', '空路径 / `/` / `//` 都归一成根；只有注册为空路径或 `/` 的静态路由能命中', function (): void {
        $t = table([
            ['GET', '/', 'root'],
            ['GET', '/a', 'a'],
        ]);

        expect_same('root', rt_hit($t, 'GET', '', '空串应命中 root')['handler'], "'' -> root");
        expect_same('root', rt_hit($t, 'GET', '/', " '/' 应命中 root")['handler'], "'/' -> root");
        expect_same('root', rt_hit($t, 'GET', '//', "'//' 应命中 root")['handler'], "'//' -> root");
        expect_same('a', rt_hit($t, 'GET', '/a', '/a 应命中 a 而不是 root')['handler'], '/a -> a');

        $t2 = table([['GET', '', 'empty']]);
        expect_same('empty', rt_hit($t2, 'GET', '/', '注册空串应命中 /')['handler'], "'' 注册 -> /");
        expect_same(null, (new RouteTable())->match('GET', '/'), '空表返回 null');
        expect_same(null, $t->match('GET', '/b'), '未注册返回 null');
    }],
];

// ---------------------------------------------------------------------------
// [dynamic] 2 —— 契约 2、6、7
// ---------------------------------------------------------------------------

$dynamic = [
    ['capture', '`{name}` 匹配一段（不含 `/`），捕获值百分号解码（先分段后解码）', function (): void {
        $t = table([
            ['GET', '/users/{id}', 'show'],
            ['GET', '/echo/{x}', 'echo'],
        ]);

        $m = mt_hit($t, 'GET', '/users/42', 'GET /users/42 应命中');
        expect_same('show', $m['handler'], 'handler');
        expect_same('/users/{id}', $m['pattern'], 'pattern 原样');
        expect_params(['id' => '42'], $m['params'], '捕获表');

        expect_same(null, Matcher::match($t, 'GET', '/users'), '段数不足 -> null');
        expect_same(null, Matcher::match($t, 'GET', '/users/1/2'), '段数过多 -> null');
        expect_same(null, Matcher::match($t, 'POST', '/users/42'), 'method 不匹配 -> null');
        expect_same(null, Matcher::match($t, 'GET', '/other/1'), '静态段不符 -> null');

        expect_params(
            ['x' => 'a/b'],
            mt_hit($t, 'GET', '/echo/a%2Fb', 'GET /echo/a%2Fb 应命中')['params'],
            '%2F 解码成 / 但不改变分段'
        );
        expect_params(
            ['x' => '中'],
            mt_hit($t, 'GET', '/echo/%E4%B8%AD', 'GET /echo/%E4%B8%AD 应命中')['params'],
            'UTF-8 百分号解码'
        );
        expect_params(
            ['x' => '/'],
            mt_hit($t, 'GET', '/echo/%2F', 'GET /echo/%2F 应命中')['params'],
            '整段就是一个编码过的斜杠'
        );
    }],

    ['repeat', '同名段重复出现时必须值相等，否则不匹配', function (): void {
        $t = table([['GET', '/diff/{a}/vs/{a}', 'same']]);

        $m = mt_hit($t, 'GET', '/diff/7/vs/7', '同名段值相等应命中');
        expect_same('same', $m['handler'], 'handler');
        expect_params(['a' => '7'], $m['params'], '同名段只算一次');

        expect_same(null, Matcher::match($t, 'GET', '/diff/7/vs/8'), '同名段值不等 -> null');
        expect_same(null, Matcher::match($t, 'GET', '/diff/7/vs'), '段数不足 -> null');

        $t2 = table([['GET', '/a/{x}/c', 'mix']]);
        expect_same('mix', mt_hit($t2, 'GET', '/a/1/c', '混合静态/动态应命中')['handler'], '混合命中');
        expect_same(null, Matcher::match($t2, 'GET', '/a/1/d'), '末尾静态段不符 -> null');
        expect_same(null, Matcher::match($t2, 'GET', '/b/1/c'), '首段静态不符 -> null');
    }],
];

// ---------------------------------------------------------------------------
// [optional] 2 —— 契约 3
// ---------------------------------------------------------------------------

$optional = [
    ['tail', '`{name?}` 整段可缺省；出现时捕获，缺省时不出现在捕获表里', function (): void {
        $t = table([['GET', '/list/{page?}', 'page']]);

        expect_same('page', mt_hit($t, 'GET', '/list', '/list 应命中')['handler'], '缺省命中');
        expect_params([], mt_hit($t, 'GET', '/list', '/list 应命中')['params'], '缺省时无捕获');
        expect_params(['page' => '2'], mt_hit($t, 'GET', '/list/2', '/list/2 应命中')['params'], '出现时捕获');
        expect_same(null, Matcher::match($t, 'GET', '/list/2/3'), '可选段只吃一段 -> null');
        expect_same(null, Matcher::match($t, 'GET', '/other'), '静态段不符 -> null');

        $t2 = table([['GET', '/{a?}', 'root-opt']]);
        expect_same('root-opt', mt_hit($t2, 'GET', '/', '整条模式只有一个可选段时应命中根')['handler'], '根命中');
        expect_params([], mt_hit($t2, 'GET', '/', '缺省无捕获')['params'], '根捕获为空');
        expect_params(['a' => 'z'], mt_hit($t2, 'GET', '/z', '/z 应命中')['params'], '一段时捕获');
    }],

    ['illegal', '中间出现可选段 / 中间出现尾部通配 / 未知修饰符 / 空段名 → RouteException', function (): void {
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{x?}/b', 'bad']]), 'GET', '/a/1/b'),
            '可选段出现在中间'
        );
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{x:path*}/b', 'bad']]), 'GET', '/a/1/b'),
            '尾部通配出现在中间'
        );
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{x:foo}', 'bad']]), 'GET', '/a/1'),
            '未知修饰符'
        );
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{}', 'bad']]), 'GET', '/a/1'),
            '空段名'
        );
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{x', 'bad']]), 'GET', '/a/1'),
            '未闭合的段'
        );
        expect_route_exception(
            static fn () => Matcher::match(table([['GET', '/a/{x?}/{y}', 'bad']]), 'GET', '/a/1'),
            '可选段出现在中间（后面还有段）'
        );
    }],
];

// ---------------------------------------------------------------------------
// [wildcard] 1 —— 契约 4、6
// ---------------------------------------------------------------------------

$wildcard = [
    ['tail', '`{name:path*}` 吃掉剩余全部（含 `/`），可以为空；捕获值百分号解码', function (): void {
        $t = table([['GET', '/files/{p:path*}', 'files']]);

        expect_params(['p' => ''], mt_hit($t, 'GET', '/files', '通配可以为空')['params'], '空捕获是空串');
        expect_params(
            ['p' => 'a/b/c'],
            mt_hit($t, 'GET', '/files/a/b/c', '吃掉剩余全部')['params'],
            '含 / 的剩余段'
        );
        expect_params(
            ['p' => 'a/b'],
            mt_hit($t, 'GET', '/files/a%2Fb', '编码过的斜杠先分段后解码')['params'],
            '%2F 先分段后解码'
        );
        expect_same('files', mt_hit($t, 'GET', '/files/x', '前缀命中')['handler'], 'handler');
        expect_same(null, Matcher::match($t, 'GET', '/other/x'), '前缀静态段不符 -> null');
        expect_same(null, Matcher::match($t, 'GET', '/filesx/x'), '前缀必须整段相等 -> null');

        $t2 = table([['GET', '/{p:path*}', 'all']]);
        expect_params(['p' => 'a/b'], mt_hit($t2, 'GET', '/a/b', '整条模式就是通配')['params'], '整路径通配');
        expect_params(['p' => ''], mt_hit($t2, 'GET', '/', '通配命中根')['params'], '根 -> 空捕获');
    }],
];

// ---------------------------------------------------------------------------
// [priority] 2 —— 契约 5、9
// ---------------------------------------------------------------------------

$priority = [
    ['registration-order', '同优先级的静态路由按注册顺序先到先得；同一表多次匹配结果相同', function (): void {
        $t = table([
            ['GET', '/x', 'first'],
            ['GET', '/x', 'second'],
            ['GET', '/y', 'other'],
        ]);

        expect_same(3, $t->count(), '路由条数');
        expect_same(['GET', 'GET', 'GET'], array_column($t->routes(), 'method'), '内部顺序 = 注册顺序');
        expect_same(['/x', '/x', '/y'], array_column($t->routes(), 'pattern'), 'pattern 顺序');
        expect_same('first', rt_hit($t, 'GET', '/x', '同模式重复注册先到先得')['handler'], '先到先得');

        $a = $t->match('GET', '/x');
        $b = $t->match('GET', '/x');
        $c = $t->match('GET', '/x');
        expect_same($a, $b, '同一表两次匹配逐字段相同');
        expect_same($b, $c, '同一表三次匹配逐字段相同');
        expect_same('other', rt_hit($t, 'GET', '/y', '/y 命中 other')['handler'], '/y -> other');
        expect_same(null, $t->match('GET', '/z'), '未注册 -> null');
    }],

    ['precedence', '静态 > 动态 > 可选 > 通配；同种类按注册顺序（与表的遍历顺序无关）', function (): void {
        // 动态段注册在静态段之前，静态段仍必须胜出。
        $t1 = table([
            ['GET', '/{a}', 'dyn'],
            ['GET', '/users', 'stat'],
        ]);
        expect_same('stat', mt_hit($t1, 'GET', '/users', '静态应压过先注册的动态')['handler'], '静态 > 动态');
        expect_same('dyn', mt_hit($t1, 'GET', '/other', '不冲突时动态照常命中')['handler'], '动态命中');
        expect_params(['a' => 'other'], mt_hit($t1, 'GET', '/other', '动态捕获')['params'], '动态捕获');

        // 通配注册最早，动态 / 可选仍必须胜出。
        $t2 = table([
            ['GET', '/{w:path*}', 'wc'],
            ['GET', '/{o?}', 'opt'],
            ['GET', '/{d}', 'dyn'],
        ]);
        expect_same('dyn', mt_hit($t2, 'GET', '/x', '一段路径：动态 > 可选 > 通配')['handler'], '动态 > 可选 > 通配');
        expect_same('opt', mt_hit($t2, 'GET', '/', '零段路径：可选 > 通配')['handler'], '可选 > 通配');
        expect_params([], mt_hit($t2, 'GET', '/', '可选缺省无捕获')['params'], '可选缺省');

        // 同种类：按注册顺序先到先得。
        $t3 = table([
            ['GET', '/u/{x}/z', 'first'],
            ['GET', '/u/{y}/z', 'second'],
        ]);
        expect_same('first', mt_hit($t3, 'GET', '/u/9/z', '同为动态段按注册顺序')['handler'], '动态同种类注册顺序');
        expect_params(['x' => '9'], mt_hit($t3, 'GET', '/u/9/z', '先注册者捕获')['params'], '先注册者参数');

        // 与注册顺序完全反向：结果不能变。
        $t4 = table([
            ['GET', '/users', 'stat'],
            ['GET', '/{a}', 'dyn'],
        ]);
        expect_same('stat', mt_hit($t4, 'GET', '/users', '静态在静态优先于动态（与注册顺序无关）')['handler'], '换序后仍静态胜');
    }],
];

// ---------------------------------------------------------------------------
// decoding 组：路径段百分号解码（恰好一次）
// ---------------------------------------------------------------------------

$decoding = [
    ['static-encoded', '静态段比较前先解码路径段', function (): void {
        $t = table([['GET', '/users', 'u']]);
        expect_same('u', mt_hit($t, 'GET', '/%75sers', '静态段应按解码后的值匹配')['handler'], '解码静态段');
    }],
    ['decode-once', '捕获值只解码一次，不二次展开', function (): void {
        $t = table([['GET', '/files/{name}', 'f']]);
        expect_params(['name' => 'a%2Fb'], mt_hit($t, 'GET', '/files/a%252Fb', '双编码只消一层')['params'], '只解码一次');
    }],
    ['encoded-slash-and-invalid', '编码斜杠不拆分；非法百分号抛 RouteException', function (): void {
        $t = table([['GET', '/a/{x}/b', 'x']]);
        expect_params(['x' => '/'], mt_hit($t, 'GET', '/a/%2F/b', '解码后的 / 仍是一段')['params'], '编码斜杠');
        try {
            mt_hit($t, 'GET', '/a/%ZZ/b', '非法转义应抛 RouteException');
            throw new CheckFailure('-', '正常返回', '非法百分号转义未报错');
        } catch (RouteException $ok) {
            // expected
        }
    }],
];
$GROUPS = [
    'static' => $static,
    'dynamic' => $dynamic,
    'optional' => $optional,
    'wildcard' => $wildcard,
    'priority' => $priority,
    'decoding' => $decoding,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as [$name, $why, $fn]) {
        $flat[] = [$group, $name, $why, $fn];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（static/dynamic/optional/wildcard/priority）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-9s] %-18s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$total = count($selected);

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

printf("结果：通过 %d/%d\n", $passed, $total);

exit($passed === $total && $total > 0 ? 0 : 1);
