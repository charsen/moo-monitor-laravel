<?php declare(strict_types=1);

namespace Mooeen\Monitor\Recorder\Concerns;

/**
 * URL 脱敏:把 query string 里命中 mask_keys 的参数值替成 ***。
 *
 * RuntimeErrorRecorder 与 SqlSlowRecorder 共用 —— 保证两条采集链路对密钥类 query 参数
 * (token / secret / api_key …)一致脱敏后再落盘 / 推云端,避免「runtime 脱了、慢 SQL 没脱」
 * 这种泄露面不一致。依赖宿主类有 $this->config['mask_keys'](数组,子串匹配、大小写不敏感)。
 */
trait MasksSensitiveUrl
{
    /**
     * 把 url query 里命中 mask_keys 的参数值替为 ***，scheme/host/path/fragment 原样保留。
     * 命中规则:大小写不敏感、子串匹配（'token' 命中 'access_token'）。
     */
    protected function maskUrl(string $url): string
    {
        $qPos = strpos($url, '?');
        if ($qPos === false) {
            return $url;
        }
        $prefix = substr($url, 0, $qPos);
        $query  = substr($url, $qPos + 1);
        // fragment(#...) 单独切，避免被当 query 解
        $frag = '';
        if (($fPos = strpos($query, '#')) !== false) {
            $frag  = substr($query, $fPos);
            $query = substr($query, 0, $fPos);
        }
        if ($query === '') {
            return $url;
        }
        $parts = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $parts[] = $pair;

                continue;
            }
            $k       = substr($pair, 0, $eq);
            $parts[] = $this->shouldMaskKey(urldecode($k)) ? $k . '=***' : $pair;
        }

        return $prefix . '?' . implode('&', $parts) . $frag;
    }

    /**
     * SQL / 异常文本的值侧脱敏:把「敏感列 比较 值」里的值替成 ***。
     * URL 脱敏只覆盖 query 参数;这条补 sql_last(binding 替换后的 SQL)与 QueryException message —— 例:
     *   `WHERE api_token = 'sk-live-…'` / `password='x'` / `secret IN ('a','b')` → 列名后的字面量替 ***。
     * 列名按 mask_keys 子串匹配(同 shouldMaskKey)。sql_raw(? 占位形态)不含字面值,无需脱敏。
     */
    protected function maskSensitiveSql(string $text): string
    {
        $keys = array_filter(array_map('strval', (array) ($this->config['mask_keys'] ?? [])));
        if ($keys === [] || $text === '') {
            return $text;
        }
        $alt     = implode('|', array_map(static fn ($k) => preg_quote($k, '/'), $keys));
        $pattern = '/([`"\[]?[a-z0-9_.]*(?:' . $alt . ')[a-z0-9_.]*[`"\]]?\s*'
            . '(?:=|!=|<>|>=|<=|>|<|\bLIKE\b|\bIN\b)\s*)'
            . '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|\([^)]*\)|[0-9.]+)/i';

        return preg_replace($pattern, '$1***', $text) ?? $text;
    }

    /**
     * 自由文本(异常 message / trace)里的通用密钥脱敏 —— maskSensitiveSql 只认「列 OP 值」的
     * SQL 形态,但应用常把 JWT / Bearer token / 裸密钥直接写进 message 或当函数参数进 trace。
     * 这里补:① JWT(eyJ.xxx.yyy)② Bearer <token> ③ 键名命中 mask_keys 的 key:value / key=value。
     */
    protected function maskSecrets(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // ① JWT(三段 base64url)
        $text = preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '***JWT***', $text) ?? $text;
        // ② Bearer <token>
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $text) ?? $text;
        // ③ key: value / key = value(键名命中 mask_keys,值打码)
        $keys = array_filter(array_map('strval', (array) ($this->config['mask_keys'] ?? [])));
        if ($keys !== []) {
            $alt  = implode('|', array_map(static fn ($k) => preg_quote($k, '/'), $keys));
            $text = preg_replace(
                '/([a-z0-9_.\-]*(?:' . $alt . ')[a-z0-9_.\-]*\s*["\']?\s*[:=]\s*["\']?)([^\s"\',&;]+)/i',
                '$1***',
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * key 是否命中 mask_keys(子串、大小写不敏感)。maskRecursive(payload 脱敏)也复用本判断。
     */
    protected function shouldMaskKey(string $key): bool
    {
        $keys  = (array) ($this->config['mask_keys'] ?? []);
        $lower = strtolower($key);
        foreach ($keys as $needle) {
            if (str_contains($lower, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
