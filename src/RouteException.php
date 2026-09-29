<?php

declare(strict_types=1);

/**
 * routepat 的统一异常类型。
 *
 * 非法的路由模式（中间出现可选段 / 中间出现尾部通配 / 未知的 `{...}` 修饰符）
 * 一律以 RouteException 结束，不得让原生 TypeError / Warning 泄漏出去。
 * 这是一个纯异常类型，没有需要实现的方法体。
 */
class RouteException extends RuntimeException
{
}
