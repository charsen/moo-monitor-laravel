<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use RuntimeException;

/**
 * moo:cloud:test 自检专用异常。
 *
 * 单独成类是为了「可识别」:云端按异常类聚合展示,看到 Mooeen\Monitor\SelfTestException 即知这是
 * 连通性自检产生的测试记录(可安全忽略 / 解决),不会被误当成宿主真实 bug。
 */
class SelfTestException extends RuntimeException {}
