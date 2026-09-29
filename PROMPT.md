现有的路由只认静态段，动态段、可选段、尾部通配和编码路径都得补齐。routepat 是 PHP 8 的
路由模式匹配库（php-cli，无 composer，无第三方依赖），构建不需要额外步骤，
自检走 `scripts/check.sh`（`check/` 是固定验收程序，别改），既有用例走 `php tests/run.php`。

`RouteTable` 已经能用；`Matcher` 是这次要补的那一层，现在方法体全抛错。路径要先归一化，
再按段解码；编码斜杠不能变成新分隔符，捕获值只能解码一次。

任务：按 README「对外契约」的 11 条把动态段、可选段、尾部通配、优先级和路径解码做出来。

验收：
- php tests/run.php 全绿；
- php check/check.php 退出码 0，12 个场景全过
  （static 2 + dynamic 2 + optional 2 + wildcard 1 + priority 2 + decoding 3）。

约束：
1. 不改 `check/`、不改 `src/RouteException.php` 与 `RouteTable`；可以新增类文件。
2. 对外方法名与签名不许改；`tests/run.php` 里的既有用例一条都不许删或改。
3. 不许用 composer，不许引入任何第三方库；不许用 mbstring 扩展。
4. 结果不许依赖数组遍历顺序；非法百分号转义必须抛 RouteException。