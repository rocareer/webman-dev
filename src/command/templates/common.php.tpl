<?php
/**
 * rocareer/{{NAME}} 公共函数
 */

if (!function_exists('{{NAME}}_config')) {
    /**
     * 读取包配置（config/plugin/rocareer/{{NAME}}/*.php）
     */
    function {{NAME}}_config(string $key, mixed $default = null): mixed
    {
        return config('plugin.rocareer.{{NAME}}.' . $key, $default);
    }
}
