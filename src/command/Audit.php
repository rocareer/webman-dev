<?php
/**
 * rocareer:audit — rocareer 基础设施包工程质量审计
 *
 * 规则实现集中在 app\admin\service\AuditService（v3.1.0 起 CLI 与后台
 * 「开发运维 → 工程质量审计」管理页共用同一引擎），本命令只负责根目录探测与结果输出。
 *
 * 用法：php webman rocareer:audit [--root=包目录根] [--pkg=ai]
 *   --root：含 radmin/ 等包目录的 src 根（工作区为 <Rocareer>/src）；不传则自动向上探测，
 *          兼容传工作区根（内部落到 <workspace>/src）。
 *
 *   --list-rules                     只打印规则目录（code/名称/判定标准与修复指引），不执行审计
 *   --list-rules --json              规则目录以 JSON 输出（供脚本/AI 消费）
 *   --list-rules --write-doc=路径    把规则目录写成自动生成的 Markdown 自检清单（如 docs/audit-rules.md）
 *
 * 规则目录单一真源 = AuditService::RULES（本命令只渲染，不维护副本），
 * 故文档不会与引擎漂移：改规则后重跑 --write-doc 即同步。
 */

namespace Rocareer\WebmanDev\command;

use app\admin\service\AuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Audit extends Command
{
    protected static $defaultName = 'rocareer:audit';

    protected static $defaultDescription = 'Audit rocareer infrastructure packages';

    /** 默认审计包（单点归属 AuditService，CLI 与 MCP 工具共用） */
    protected const DEFAULT_PACKAGES = \app\admin\service\AuditService::DEFAULT_PACKAGES;

    protected int $failCount = 0;

    protected function configure()
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, '包目录根（含 radmin 等包目录）');
        $this->addOption('pkg', null, InputOption::VALUE_REQUIRED, '仅审计单个包');
        $this->addOption('list-rules', null, InputOption::VALUE_NONE, '打印规则目录（不执行审计）');
        $this->addOption('json', null, InputOption::VALUE_NONE, '配合 --list-rules：JSON 输出');
        $this->addOption('write-doc', null, InputOption::VALUE_REQUIRED, '配合 --list-rules：写出自动生成的 Markdown 自检清单');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list-rules')) {
            return $this->listRules($input, $output);
        }

        $service = new AuditService();
        $root = $this->detectRoot($input->getOption('root'));
        if ($root === '') {
            $output->writeln('<error>workspace root not found（请用 --root 指定含 radmin 的包目录根）</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>rocareer:audit @ ' . $root . '</info>');
        $pkgs = $input->getOption('pkg') ? [$input->getOption('pkg')] : self::DEFAULT_PACKAGES;

        $result = $service->audit($root, $pkgs);
        $done = [];
        foreach ($result['packages'] as $pkg) {
            if (in_array($pkg['name'], $done, true)) {
                continue;
            }
            $done[] = $pkg['name'];
            $output->writeln('');
            $output->writeln('<options=bold>===== Package: ' . $pkg['name'] . ' =====</>');
            foreach ($pkg['rules'] as $rule) {
                $this->printRule($output, $rule);
            }
        }
        $output->writeln('');
        if ($this->failCount > 0) {
            $output->writeln("<comment>Audit finished with {$this->failCount} issue(s)</comment>");
            return self::FAILURE;
        }
        $output->writeln('<info>Audit passed: all packages clean</info>');
        return self::SUCCESS;
    }

    /**
     * 输出单条规则结果（与旧版输出格式一致：SKIP / PASS(note) / FAIL + 问题明细）
     */
    protected function printRule(OutputInterface $output, array $rule): void
    {
        [$code, $title, $pass, $skipped, $count, $issues, $note, $skip] = [
            $rule['code'], $rule['title'], $rule['pass'], $rule['skipped'],
            $rule['count'], $rule['issues'], $rule['note'], $rule['skip'],
        ];
        $label = $this->ruleLabel($code);
        if ($skipped) {
            $output->writeln('<comment>[NOT-APPLICABLE]</comment> ' . $label . ($skip !== '' ? ': ' . $skip : ''));
            return;
        }
        if ($pass) {
            $output->writeln('<info>[PASS]</info> ' . $label . ($note !== '' ? ' (' . $note . ')' : ''));
            return;
        }
        $output->writeln('<fg=red>[FAIL]</fg=red> ' . $label . ' (' . $count . ' issue(s)):');
        foreach (array_slice($issues, 0, 15) as $line) {
            // 常规规则 issue 为字符串；coverage 等合成的 issue 为 ['file','line','message'] 数组
            if (is_array($line)) {
                $file = (string) ($line['file'] ?? '');
                $ln = (int) ($line['line'] ?? 0);
                $msg = (string) ($line['message'] ?? '');
                $line = $file . ($ln > 0 ? ':' . $ln : '') . ($msg !== '' ? ' ' . $msg : '');
            }
            $output->writeln('       ' . $line);
        }
        if ($count > count($issues)) {
            $output->writeln('       … 还有 ' . ($count - count($issues)) . ' 条');
        }
        $this->failCount += $count;
    }

    /**
     * 打印/写出规则目录（单一真源 AuditService::RULES）
     *
     * 用途：给 AI/人一份「写码自检清单」，免去逐份翻 AGENTS.md 与各 SKILL.md；
     * 因直接从引擎常量渲染，规则演进后重跑即同步，不存在文档漂移。
     */
    protected function listRules(InputInterface $input, OutputInterface $output): int
    {
        $rules = AuditService::RULES;
        $asJson = (bool) $input->getOption('json');
        $docPath = (string) ($input->getOption('write-doc') ?? '');

        // --write-doc：落盘自动生成文档（不打印，避免与审计输出混淆）
        if ($docPath !== '') {
            $dir = dirname($docPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($docPath, $this->renderRulesDoc($rules));
            $output->writeln('<info>规则清单已写出：' . $docPath . '（' . count($rules) . ' 条规则，由引擎常量自动生成，勿手工编辑）</info>');
            return self::SUCCESS;
        }

        if ($asJson) {
            $out = [];
            foreach ($rules as $code => $meta) {
                $out[] = ['code' => $code, 'title' => $meta['title'], 'description' => $meta['description']];
            }
            $output->writeln(json_unicode(['count' => count($rules), 'rules' => $out], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $output->writeln('<info>rocareer:audit 规则目录（' . count($rules) . ' 条，真源 AuditService::RULES）</info>');
        $output->writeln('');
        $i = 0;
        foreach ($rules as $code => $meta) {
            $i++;
            $output->writeln('<options=bold>' . $i . '. ' . $meta['title'] . ' (#' . $code . ')</>');
            $output->writeln('   ' . $meta['description']);
            $output->writeln('');
        }
        $output->writeln('<comment>豁免：文件内标注 @audit-ignore &lt;code&gt; 可跳过该规则（须注明理由）。</comment>');
        $output->writeln('<comment>运行：php webman rocareer:audit [--pkg=包名]；导出文档：--list-rules --write-doc=docs/audit-rules.md</comment>');
        return self::SUCCESS;
    }

    /**
     * 渲染规则清单 Markdown（自动生成物，顶部标注勿手工编辑）
     */
    protected function renderRulesDoc(array $rules): string
    {
        $lines = [];
        $lines[] = '# rocareer 工程质量审计规则清单';
        $lines[] = '';
        $lines[] = '> **本文件由 `php webman rocareer:audit --list-rules --write-doc=docs/audit-rules.md` 自动生成，请勿手工编辑。**';
        $lines[] = '> 单一真源 = `webman-dev/src/app/admin/service/AuditService.php` 的 `RULES` 常量；';
        $lines[] = '> 规则有任何增改，重跑上述命令即可同步，故本清单不会与引擎漂移。';
        $lines[] = '';
        $lines[] = '审计引擎在**代码生成/提交/发版**前提供统一门禁（`rocareer:audit` CLI、MCP `quality_audit`、';
        $lines[] = '后台「开发和调试 → 工程质量审计」三入口共用同一引擎）。写码时按下表自查，可避免绝大多数返工。';
        $lines[] = '';
        $lines[] = '## 规则索引';
        $lines[] = '';
        $lines[] = '| # | 规则 | code | 一句话 |';
        $lines[] = '|---|---|---|---|';
        $i = 0;
        foreach ($rules as $code => $meta) {
            $i++;
            $brief = mb_substr(explode('：', $meta['description'])[0], 0, 60);
            $lines[] = "| {$i} | {$meta['title']} | `{$code}` | " . str_replace('|', '\\|', $brief) . ' |';
        }
        $lines[] = '';
        $lines[] = '## 规则详述';
        $lines[] = '';
        $i = 0;
        foreach ($rules as $code => $meta) {
            $i++;
            $lines[] = "### {$i}. {$meta['title']}";
            $lines[] = '';
            $lines[] = '- **code**：`' . $code . '`';
            $lines[] = '- **判定与修复**：' . $meta['description'];
            $lines[] = '';
        }
        $lines[] = '## 豁免机制';
        $lines[] = '';
        $lines[] = '文件内标注 `@audit-ignore <code>` 可跳过对应规则；用于有意的例外（如跨表字段别名映射、';
        $lines[] = '真源同步副本、CLI 回退路径），须同时写明理由，便于后人复核。';
        $lines[] = '';
        $lines[] = '## 使用方式';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = 'php webman rocareer:audit                  # 全量审计（默认包集）';
        $lines[] = 'php webman rocareer:audit --pkg=radmin     # 单包审计（改动后自查）';
        $lines[] = 'php webman rocareer:audit --list-rules     # 打印本清单';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'MCP 侧等价工具：`quality_audit`（集合 `dev`，子端点 `/mcp/dev`）。';
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * 规则 code -> CLI 展示名（迁移/按钮节点/页面用服务内置元数据 title）
     */
    protected function ruleLabel(string $code): string
    {
        return [
            'php_syntax' => 'php -l',
            'controller' => 'controllers',
            'permission' => 'permission nodes',
            'migration' => 'migrations timestamps',
            'residue' => 'residue',
            'version' => 'version sync',
            'web_page' => 'web pages',
            'async_blocking' => 'async blocking',
            'fqcn_dup' => 'FQCN duplicates',
            'superglobal' => 'superglobals',
            'dead_code' => 'dead classes',
            'cross_copy' => 'cross-package copies',
            'happ_frontend' => 'happ frontend sdk',
            'dto_contract' => 'DTO contract',
            'llm_gate' => 'LLM gateway gate',
            'orm_migrated' => 'ORM migrated',
            'event_standard' => 'event standard',
            'common_utils' => 'common utils',
            'install_standard' => 'install standard',
            'comsearch_contract' => 'comSearch 契约（禁手写解析 search）',
            'coverage' => 'audit coverage',
        ][$code] ?? $code;
    }

    /**
     * 根目录探测：接受含 radmin 的 src 根或含 src/radmin 的工作区根，统一返回 src 根
     */
    protected function detectRoot(?string $root): string
    {
        $service = new AuditService();
        $candidates = [];
        if ($root) {
            $candidates[] = $root;
        }
        $dir = getcwd() ?: '.';
        while (true) {
            $candidates[] = $dir;
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        foreach ($candidates as $cand) {
            $resolved = $service->resolveCandidate($cand);
            if ($resolved !== '') {
                return $resolved;
            }
        }
        return '';
    }
}