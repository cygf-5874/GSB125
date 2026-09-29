# routepat —— PHP 8 的路由模式匹配库

一张路由表 + 一层「把请求路径对到注册模式」的匹配器：

- 语言/框架：PHP 8，**仅标准库**（php-cli，无 composer，无第三方依赖，不用 mbstring）。
- 自检入口：`bash scripts/check.sh`（内部就是 `php check/check.php`）。
  `check/` 是**固定验收程序**，不要改。
- 三个类在 `src/` 下：`RouteTable`（路由表 + 请求路径归一化 + 静态匹配）、
  `Matcher`（模式匹配层）、`RouteException`（非法模式）。

## 快速开始

```bash
php tests/run.php                # 既有用例，13 个
bash scripts/check.sh            # 跑全部 12 个场景，全过才 exit 0
bash scripts/check.sh -list      # 列出全部场景
bash scripts/check.sh --only dynamic
```

## 公开 API

```php
// —— 路由表（既有，已可用）——
$t = new RouteTable();
$t->add(string $method, string $pattern, mixed $handler = null): void   // method 大小写不敏感
$t->routes(): array            // 注册顺序的 list<array{method,pattern,handler}>
$t->count(): int
$t->match(string $method, string $path): ?array   // 只认静态段；见契约 1

RouteTable::normalizePath(string $path): string   // 请求路径归一化（契约 7）

// —— 模式匹配层（本次要补）——
Matcher::match(RouteTable $table, string $method, string $path): ?array
Matcher::compile(string $pattern): array          // 非法模式 -> RouteException
Matcher::capture(array $compiled, array $segments): ?array
```

`match()` 命中时返回：

```php
['pattern' => 原样模式, 'method' => 大写方法, 'handler' => 注册时的 handler,
 'params' => ['名字' => '捕获值', …]]
```

不命中返回 `null`。公开方法名与签名已定死，不得更改；可以新增类文件。

## 对外契约（11 条）

1. **既有行为不得回归**：`RouteTable::add()` / `routes()` / `count()` / `normalizePath()` /
   只认静态段的 `RouteTable::match()` 全部保持现状；静态路径精确匹配、`method` 不匹配返回 `null`、
   未注册返回 `null`。`Matcher::match()` 对静态路由必须给出与 `RouteTable::match()` **一致**的结果。
2. **动态段 `{name}`**：匹配**一段**（不含 `/`），把该段原始文本**百分号解码**后捕获进 `params`；
   同名段在一条模式里重复出现时，几次捕获的**值必须相等**，否则整条不匹配。
3. **可选段 `{name?}`**：整段可缺省 —— 缺省时**不出现**在 `params` 里，出现时按动态段处理。
   可选段**只允许出现在末尾**；出现在中间 → `RouteException`。
4. **尾部通配 `{name:path*}`**：吃掉剩余的全部段（含 `/`），**可以为空**（此时捕获值为 `''`）；
   它总是出现在 `params` 里。只允许出现在末尾；出现在中间 → `RouteException`。
5. **优先级**：**静态段 > 动态段 > 可选段 > 通配**；同优先级按**注册顺序**先到先得。
   「静态 > 动态 > …」是**逐段**比较：两条模式从左往右第一处「段种类」不同的位置决定高下。
   整张表的匹配结果**不得**随数组遍历顺序变化 —— 必须显式排序后再定优先级。
6. **百分号解码**：捕获值用 `rawurldecode()`（`+` **不**还原成空格）。
   解码发生在**分段之后**：`%2F` 解码成 `/`，但**不**因此多切出一段。
7. **请求路径归一化**（`RouteTable::normalizePath()`，既有）：剥掉查询串（第一个 `?` 起）、
   折叠连续 `/`、去掉末尾 `/`（根除外）；空串等价于 `/`。查询串不参与匹配。
8. **不匹配返回 `null`**；归一化后为空或 `/` 的请求路径，**静态路由**里只有注册为 `''` 或 `'/'`
   的能命中它；注册为 `''` 与 `'/'` 的模式等价。末尾可选段与尾部通配因为「整段可缺省 / 可以为空」，
   允许吃掉**零**个段（所以 `/{a?}`、`/{p:path*}` 能命中 `/`）。
9. **纯函数**：同一张 `RouteTable` 上多次 `match()` 结果逐字段相同，不修改表的内部状态；
   不使用时间 / 随机源 / `spl_object_id()`；结果与 PHP 数组的 hash 遍历顺序无关。

## 本次要补的一层

`RouteTable`（含请求路径归一化与静态匹配）已经能用，`src/RouteException.php` 已经给全。
要补的是 `src/Matcher.php` 里的三个空壳方法（现在方法体全抛 `LogicException`）：
**动态段（契约 2）、可选段（契约 3）、尾部通配（契约 4）、优先级（契约 5）、解码语义（契约 6）。**

补充说明（只是把判据说死）：

- 模式与请求路径都按 `/` 分段；模式的**首尾 `/` 忽略、连续 `/` 折叠**，因此 `''`、`'/'`、`'//'`
  表示同一个「零段」模式。模式里的 `?` 属于 `{name?}` 的语法，**不**当查询串处理。
- 段名只接受 `[A-Za-z_][A-Za-z0-9_]*`；`{}`、`{x`（未闭合）、`{x:foo}`（未知修饰符）一律 `RouteException`。
- 静态段的比较用**原始**（未解码）文本；动态段 / 可选段 / 通配段的捕获值才解码。
- 非法模式在 `Matcher::match()` 里**无论 method 是否匹配**都要抛 `RouteException`。

## 目录

```
README.md             本文件
PROMPT.md             出题用的 User Prompt
autoload.php          极简 spl_autoload_register（__DIR__ 相对定位 src/）
src/RouteTable.php    路由表 + 请求路径归一化 + 静态匹配（既有）
src/Matcher.php       模式匹配层            ← 本次要补的一层
src/RouteException.php 非法模式异常
tests/run.php         既有用例，13 个，当前全绿
check/check.php       固定验收程序，9 个场景（别改）
scripts/check.sh      check.php 的薄封装
```
