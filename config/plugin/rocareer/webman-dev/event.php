<?php
/**
 * webman-dev 事件监听器
 *
 * mcp.collections.register：宿主装了 rocareer/mcp 时，自动注册「工程质量审计」MCP 工具集合
 * （quality_audit，见 app\mcp\collection\AuditCollection）。
 *
 * 守卫：未装 rocareer/mcp 的宿主（interface 不存在）不注册监听——否则 BootStrap 解析监听器
 * 时 class_exists(AuditCollection) 会触发类加载，而该类 implements mcp 接口，直接 Fatal
 * （2026-10-20 实证：dev/diancan 未装 mcp 时 migrate:run/start 全部崩溃）。
 */

$listeners = [];

if (interface_exists(\app\mcp\support\McpToolCollectionInterface::class)) {
    $listeners['mcp.collections.register'] = [
        [\app\mcp\collection\AuditCollection::class, 'onRegister'],
    ];
}

return $listeners;
