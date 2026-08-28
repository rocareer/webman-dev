<?php

namespace app\mcp\collection;

use app\admin\service\AuditService;
use app\mcp\support\McpRegistry;
use app\mcp\support\McpToolCollectionInterface;

/**
 * 工程质量审计 MCP 工具集合（webman-dev 自动注册）
 *
 * 注册：本包 config/plugin/rocareer/webman-dev/event.php 监听 mcp.collections.register
 * （宿主装了 rocareer/mcp 即自动生效；未装 mcp 时本类不会被加载）。
 *
 * 提供 1 个工具 quality_audit（复用后台/CLI 同一审计引擎 AuditService）：
 *   - PHP 语法（php -l 批量子进程，fiber 非阻塞）、控制器规范、权限节点匹配、
 *     迁移时间戳查重、残留扫描、版本同步、前端页面规范 七类规则；
 *   - 重量级操作（秒级），低频使用；detail=true 返回问题明细，否则仅摘要。
 *
 * 子端点：/mcp/dev（只服务本集合工具，scope 隔离）。
 */
class AuditCollection implements McpToolCollectionInterface
{
    public function key(): string
    {
        return 'dev';
    }

    public function title(): string
    {
        return '工程质量审计（webman-dev）';
    }

    public function endpoint(): ?string
    {
        return '/mcp/dev';
    }

    /**
     * MCP 工具定义
     */
    public function tools(): array
    {
        return [
            [
                'name' => 'quality_audit',
                'description' => '工程质量审计（rocareer 基础设施包）：PHP 语法 / 控制器规范 / 权限节点 / 迁移时间戳查重 / 残留扫描 / 版本同步。'
                    . '重量级操作（秒级）；detail 默认 false 仅返回摘要，true 附带问题明细。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'root' => ['type' => 'string', 'description' => '包目录根（含 radmin 等包目录；缺省自动探测）'],
                        'pkg' => ['type' => 'string', 'description' => '仅审计单个包（缺省全部默认包）'],
                        'codes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '规则子集（缺省全部）：php_syntax/controller/permission/migration/residue/version/web_page/async_blocking/fqcn_dup/superglobal/dead_code/cross_copy/dto_contract/llm_gate'],
                        'detail' => ['type' => 'boolean', 'description' => '是否附带问题明细（缺省 false）'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 执行工具调用
     */
    public function call(string $name, array $arguments, array $context): array
    {
        if ($name !== 'quality_audit') {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => "unknown tool: {$name}"]]];
        }

        $service = new AuditService();
        $root = (string) ($arguments['root'] ?? '');
        if ($root === '') {
            $root = $service->rootPath();
        } else {
            $root = $service->resolveCandidate($root);
        }
        if ($root === '') {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => '未找到包目录根（请用 root 参数指定含 radmin 的 src 根）']]];
        }

        $pkgs = isset($arguments['pkg']) && $arguments['pkg'] !== ''
            ? [(string) $arguments['pkg']]
            : AuditService::DEFAULT_PACKAGES;
        $codes = array_values(array_filter((array) ($arguments['codes'] ?? []), 'is_string'));
        $detail = (bool) ($arguments['detail'] ?? false);

        $result = $service->audit($root, $pkgs, $codes);
        $issuesTotal = 0;
        foreach ($result['packages'] as $pkg) {
            foreach ($pkg['rules'] as $rule) {
                $issuesTotal += (int) $rule['count'];
            }
        }
        $text = $this->render($result, $detail);
        return [
            // 约定：McpRegistry::call 把本数组作为 result（数组 -> TextContent JSON 文本）
            'result' => [
                'text' => $text,
                'root' => $result['root'],
                'packages' => count($result['packages']),
                'issues_total' => $issuesTotal,
            ],
            'display_message' => sprintf('工程质量审计完成：%d 个包 / %d 个问题', count($result['packages']), $issuesTotal),
        ];
    }

    /**
     * 结果渲染：摘要 + （可选）问题明细
     */
    protected function render(array $result, bool $detail): string
    {
        $lines = [];
        $lines[] = '工程质量审计 @ ' . $result['root'];
        $lines[] = '----------';
        $total = ['packages' => 0, 'rules' => 0, 'pass' => 0, 'fail' => 0, 'skipped' => 0, 'issues' => 0];
        foreach ($result['packages'] as $pkg) {
            $total['packages']++;
            foreach ($pkg['rules'] as $rule) {
                $total['rules']++;
                if ($rule['skipped']) {
                    $total['skipped']++;
                } elseif ($rule['pass']) {
                    $total['pass']++;
                } else {
                    $total['fail']++;
                    $total['issues'] += (int) $rule['count'];
                }
            }
        }
        $lines[] = sprintf(
            '包 %d 个 / 规则执行 %d（通过 %d、失败 %d、跳过 %d）/ 问题 %d 个',
            $total['packages'], $total['rules'], $total['pass'], $total['fail'], $total['skipped'], $total['issues']
        );
        foreach ($result['packages'] as $pkg) {
            $lines[] = '';
            $lines[] = '■ ' . $pkg['name'] . '  (' . $pkg['dir'] . ')';
            foreach ($pkg['rules'] as $rule) {
                $mark = $rule['skipped'] ? '[SKIP]' : ($rule['pass'] ? '[PASS]' : '[FAIL]');
                $extra = $rule['skipped'] ? ($rule['skip'] !== '' ? ' ' . $rule['skip'] : '')
                    : ($rule['note'] !== '' ? ' ' . $rule['note'] : '');
                $lines[] = "  {$mark} {$rule['title']} (#{$rule['code']})" . ($rule['count'] > 0 ? " 问题 {$rule['count']}" : '') . $extra;
                if ($detail && !$rule['pass'] && !$rule['skipped']) {
                    foreach (array_slice($rule['issues'], 0, 20) as $issue) {
                        $lines[] = '      - ' . mb_substr((string) $issue, 0, 200);
                    }
                }
            }
        }
        return implode("\n", $lines);
    }

    /**
     * 事件监听：mcp.collections.register（mcp 存在才注册；重复注册幂等）
     */
    public static function onRegister(array $payload, string $eventName): void
    {
        if (class_exists(McpRegistry::class)) {
            McpRegistry::register(new self());
        }
    }
}
