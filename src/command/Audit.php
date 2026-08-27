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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
            $output->writeln('<comment>[SKIP]</comment> ' . $label . ($skip !== '' ? ': ' . $skip : ''));
            return;
        }
        if ($pass) {
            $output->writeln('<info>[PASS]</info> ' . $label . ($note !== '' ? ' (' . $note . ')' : ''));
            return;
        }
        $output->writeln('<fg=red>[FAIL]</fg=red> ' . $label . ' (' . $count . ' issue(s)):');
        foreach (array_slice($issues, 0, 15) as $line) {
            $output->writeln('       ' . $line);
        }
        if ($count > count($issues)) {
            $output->writeln('       … 还有 ' . ($count - count($issues)) . ' 条');
        }
        $this->failCount += $count;
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