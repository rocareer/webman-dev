<?php

declare(strict_types=1);

namespace app\admin\service;

/**
 * 业务端自定义审计规则契约（rocareer:audit 扩展协议，v3.27.0 起）
 *
 * 分工口径：**基础设施出引擎，业务端出规则**——webman-dev 的 AuditService 只提供
 * 执行服务（扫描/汇总/退出码/CLI+MCP+后台三入口），规则本体（含布局知识与领域判定）
 * 由各业务端自己定义。登记面 = 工作区根 `config/audit.php` 或包目录 `config/audit.php`
 * 的 `rules` 键（前者按 packages() 圈定适用单元，后者只作用于本包）。
 *
 * 与内置规则同款约定：
 *  - check() 返回 null = 本包不适用（输出 NOT-APPLICABLE）；
 *  - 返回 ['issues' => string[], 'note' => string] = 判定结果（issues 非空即 FAIL）；
 *  - 文件级豁免沿用 `@audit-ignore <code>` 注释约定（code 用本规则 code()）。
 */
interface AuditRuleContract
{
    /** 规则码（唯一；建议带业务域前缀，如 `mybiz.no_inline_secret`；同时用于 @audit-ignore） */
    public function code(): string;

    /** 展示名（CLI/后台/审计报告标题） */
    public function title(): string;

    /** 适用单元：['*'] 或具体单元名列表（rolling 布局 = app 与各 plugin/<名>；family = 包名） */
    public function packages(): array;

    /**
     * 执行检查（与内置规则同签名，由 AuditService 代跑）。
     *
     * @param string $root 工作区根（rolling = 工作区根；family = 包目录根）
     * @param string $pkg  单元名
     * @param string $dir  单元目录（rolling 下 app 单元 = root，插件 = plugin/<名>）
     * @return array{issues: list<string>, note: string}|null null = 不适用
     */
    public function check(string $root, string $pkg, string $dir): ?array;
}
