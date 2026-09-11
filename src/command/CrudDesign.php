<?php
/**
 * rocareer:crud-design — AI 模块设计草稿的人工审阅闸门（FACTORY P1）
 *
 * 用途：AI 产出的模块设计草稿（radmin_crud_design_draft）需要人工审阅确认后才出码——
 * 本命令是那个「人工确认」入口（红线：LLM 只产草稿，不自动出码）。
 *
 * 用法：
 *   php webman rocareer:crud-design list                 # 列出待审草稿
 *   php webman rocareer:crud-design show <id|request_id> # 查看草稿设计 JSON
 *   php webman rocareer:crud-design confirm <id> [--force] [--migrate] # 确认并出码
 *   php webman rocareer:crud-design reject <id>          # 弃用草稿
 *
 * 选项：
 *   --force      出码时覆盖已存在文件
 *   --migrate    confirm 后自动 migrate:run 建表
 *   --json       以 JSON 输出（list/show/confirm/reject 通用）
 *
 * 依赖：宿主 DB + rocareer/agent（仅 suggest 异步生成用；本命令只消费已有草稿）。
 */

namespace Rocareer\WebmanDev\command;

use app\admin\model\CrudDesignDraft;
use app\admin\service\CrudDesignAgentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class CrudDesign extends Command
{
    protected static $defaultName = 'rocareer:crud-design';

    protected static $defaultDescription = 'AI 模块设计草稿的人工审阅闸门（list/show/confirm/reject）';

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'list|show|confirm|reject');
        $this->addArgument('target', InputArgument::OPTIONAL, '草稿 id 或 request_id（show/confirm/reject 必填）');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'confirm 时覆盖已存在文件');
        $this->addOption('migrate', null, InputOption::VALUE_NONE, 'confirm 后自动 migrate:run 建表');
        $this->addOption('json', null, InputOption::VALUE_NONE, '以 JSON 输出');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        if (!class_exists(CrudDesignDraft::class)) {
            $io->error('宿主缺少 CrudDesignDraft 模型（需 rocareer/webman-dev v3.21.0+ 且已 migrate:run）');
            return self::FAILURE;
        }

        $action = (string) $input->getArgument('action');
        try {
            return match ($action) {
                'list' => $this->doList($io, $asJson),
                'show' => $this->doShow($io, $asJson, (string) $input->getArgument('target')),
                'confirm' => $this->doConfirm($io, $asJson, (string) $input->getArgument('target'),
                    (bool) $input->getOption('force'), (bool) $input->getOption('migrate')),
                'reject' => $this->doReject($io, $asJson, (string) $input->getArgument('target')),
                default => throw new \RuntimeException("未知动作：{$action}（支持 list/show/confirm/reject）"),
            };
        } catch (Throwable $e) {
            if ($asJson) {
                $io->writeln(json_unicode(['ok' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $io->error($e->getMessage());
            }
            return self::FAILURE;
        }
    }

    protected function doList(SymfonyStyle $io, bool $asJson): int
    {
        $rows = CrudDesignDraft::orderBy('id', 'desc')->limit(50)->get();
        $list = [];
        foreach ($rows as $d) {
            $list[] = [
                'id' => (int) $d->id,
                'request_id' => (string) $d->request_id,
                'table' => (string) $d->table_name,
                'status' => (string) $d->status,
                'rounds' => (int) $d->rounds,
                'prompt' => mb_substr((string) $d->prompt, 0, 40),
            ];
        }
        if ($asJson) {
            $io->writeln(json_unicode(['ok' => true, 'drafts' => $list], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        if (!$list) {
            $io->note('暂无设计草稿');
            return self::SUCCESS;
        }
        $io->table(['id', 'request_id', 'table', 'status', 'rounds', 'prompt'], array_map('array_values', $list));
        return self::SUCCESS;
    }

    protected function doShow(SymfonyStyle $io, bool $asJson, string $target): int
    {
        $d = $this->find($target);
        $payload = [
            'id' => (int) $d->id,
            'request_id' => (string) $d->request_id,
            'table' => (string) $d->table_name,
            'status' => (string) $d->status,
            'rounds' => (int) $d->rounds,
            'prompt' => (string) $d->prompt,
            'errors' => (array) $d->errors,
            'warnings' => (array) $d->warnings,
            'design' => (array) $d->design,
        ];
        if ($asJson) {
            $io->writeln(json_unicode(['ok' => true, 'draft' => $payload], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $io->writeln('草稿 #' . $d->id . '  status=' . $d->status . '  rounds=' . $d->rounds . '  table=' . $d->table_name);
        if ($d->errors) {
            $io->warning('校验错误：' . json_unicode((array) $d->errors));
        }
        $io->writeln($d->design ? json_unicode((array) $d->design, JSON_PRETTY_PRINT) : '(无设计内容)');
        return self::SUCCESS;
    }

    protected function doConfirm(SymfonyStyle $io, bool $asJson, string $target, bool $force, bool $migrate): int
    {
        $d = $this->find($target);
        $receipt = (new CrudDesignAgentService())->confirm((int) $d->id, $force);
        if (empty($receipt['ok'])) {
            if ($asJson) {
                $io->writeln(json_unicode($receipt, JSON_PRETTY_PRINT));
            } else {
                $io->error((string) ($receipt['message'] ?? '出码失败'));
                foreach (($receipt['errors'] ?? []) as $e) {
                    $io->writeln('  - [' . ($e['code'] ?? '') . '] ' . ($e['field'] ?? '') . '：' . ($e['message'] ?? ''));
                }
            }
            return self::FAILURE;
        }
        if ($migrate && !empty($receipt['migration'])) {
            $receipt['migrate'] = (new \Rocareer\WebmanDev\support\CrudDesignGenerator())->migrate();
        }
        if ($asJson) {
            $io->writeln(json_unicode($receipt, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $io->success('草稿已确认并出码：' . $receipt['table']);
        $io->writeln('  菜单：' . ($receipt['menu'] ?? ''));
        $io->writeln('  迁移：' . ($receipt['migration'] ?? '（未写）'));
        if (isset($receipt['migrate'])) {
            $io->writeln('  migrate:run：' . ($receipt['migrate']['ok'] ? '已建表' : '失败'));
        } elseif (!empty($receipt['next_step'])) {
            $io->writeln('  > ' . $receipt['next_step']);
        }
        return self::SUCCESS;
    }

    protected function doReject(SymfonyStyle $io, bool $asJson, string $target): int
    {
        $d = $this->find($target);
        (new CrudDesignAgentService())->reject((int) $d->id);
        if ($asJson) {
            $io->writeln(json_unicode(['ok' => true, 'id' => (int) $d->id, 'status' => 'rejected']));
            return self::SUCCESS;
        }
        $io->success('草稿 #' . $d->id . ' 已弃用');
        return self::SUCCESS;
    }

    /**
     * 按 id 或 request_id 定位草稿
     */
    protected function find(string $target): CrudDesignDraft
    {
        $target = trim($target);
        if ($target === '') {
            throw new \RuntimeException('缺少草稿 id 或 request_id');
        }
        $d = is_numeric($target)
            ? CrudDesignDraft::find((int) $target)
            : CrudDesignDraft::where('request_id', $target)->first();
        if (!$d) {
            throw new \RuntimeException('草稿不存在：' . $target);
        }
        return $d;
    }
}
