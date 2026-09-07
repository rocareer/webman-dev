<?php
/**
 * webman-dev 事件监听器
 *
 * mcp.collections.register：宿主装了 rocareer/mcp 时，自动注册「工程质量审计」与「标准模块生成」
 * MCP 工具集合（quality_audit / crud_generate，见 app\mcp\collection\AuditCollection 与
 * app\mcp\collection\CrudCollection）。
 *
 * 守卫：未装 rocareer/mcp 的宿主（interface 不存在）不注册监听——否则 BootStrap 解析监听器
 * 时 class_exists(集合类) 会触发类加载，而该类 implements mcp 接口，直接 Fatal
 * （2026-10-20 实证：dev/diancan 未装 mcp 时 migrate:run/start 全部崩溃）。
 */

$listeners = [];

if (interface_exists(\app\mcp\support\McpToolCollectionInterface::class)) {
    $listeners['mcp.collections.register'] = [
        [\app\mcp\collection\AuditCollection::class, 'onRegister'],
        [\app\mcp\collection\CrudCollection::class, 'onRegister'],
    ];
}

return $listeners;
