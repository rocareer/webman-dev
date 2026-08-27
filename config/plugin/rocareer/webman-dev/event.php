<?php
/**
 * webman-dev 事件监听器
 *
 * mcp.collections.register：宿主装了 rocareer/mcp 时，自动注册「工程质量审计」MCP 工具集合
 * （quality_audit，见 app\mcp\collection\AuditCollection）。未装 mcp 时本监听不会触发、无任何副作用。
 */

use app\mcp\collection\AuditCollection;

return [
    'mcp.collections.register' => [
        [AuditCollection::class, 'onRegister'],
    ],
];
