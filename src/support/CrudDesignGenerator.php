<?php

namespace Rocareer\WebmanDev\support;

use Throwable;

/**
 * CRUD 设计出码执行器（FACTORY P1）
 *
 * 把「净化的设计 JSON」执行成落盘产物：写幂等迁移 → 冲突预检 → 调 radmin CrudService
 * 生成五件套/页面/语言包/菜单。CLI（rocareer:make-crud）与 AI 确认出码
 * （CrudDesignAgentService::confirm）共用本执行器，保证两条路径产物一致、单点维护。
 *
 * 与 CrudDesigner/CrudDesignService 的分工：
 *   - CrudDesigner::parse()       = 设计 → 引擎 payload + 迁移源码（纯内存，不落盘）
 *   - CrudDesignService           = 净化 / 结构化校验 / 反导出（不落盘）
 *   - CrudDesignGenerator         = 落盘执行（写迁移 + 调引擎），返回结构化回执
 *
 * 返回回执（数组）键：ok / error_code / message / dry_run / design_version / table /
 *   comment / menu / form_fields / column_fields / target_files / migration /
 *   migration_reused / crud_log_id / next_step / warnings / errors。
 */
class CrudDesignGenerator
{
    /**
     * 执行出码
     *
     * @param array $design   净化的设计 JSON（建议先经 CrudDesignService::sanitize|validate）
     * @param bool  $noMigration 跳过写迁移（表已自行准备）
     * @param bool  $force       目标文件冲突时覆盖
     * @param bool  $dryRun      只校验预览不落盘
     * @return array 结构化回执
     */
    public function generate(array $design, bool $noMigration = false, bool $force = false, bool $dryRun = false): array
    {
        $svc = new CrudDesignService();
        $sanitized = $svc->sanitize($design);
        $errors = $svc->validate($sanitized['design']);
        $version = (int) ($sanitized['design']['version'] ?? 1);

        if ($errors) {
            return $this->fail('validation_failed', '设计校验未通过（' . count($errors) . ' 项）', [
                'errors' => $errors,
                'warnings' => $sanitized['warnings'],
            ]);
        }

        try {
            $parsed = (new CrudDesigner())->parse($sanitized['design']);
        } catch (Throwable $e) {
            return $this->fail('parse_failed', $e->getMessage(), ['warnings' => $sanitized['warnings']]);
        }
        $warnings = array_merge($sanitized['warnings'], $parsed['warnings'] ?? []);
        $base = $this->basePath();
        $targetFiles = CrudDesigner::targetFiles($parsed['table_name']);

        $common = [
            'dry_run' => $dryRun,
            'design_version' => $version,
            'table' => $parsed['table_name'],
            'comment' => $parsed['table']['comment'],
            'menu' => '/admin/' . $parsed['menu_name'] . '/index',
            'form_fields' => $parsed['table']['formFields'],
            'column_fields' => $parsed['table']['columnFields'],
            'target_files' => $targetFiles,
            'warnings' => $warnings,
        ];

        if ($dryRun) {
            return array_merge(['ok' => true], $common);
        }

        // 引擎可用性（radmin v5.1.0+ 才有 CrudService）
        if (!class_exists(\app\admin\service\CrudService::class)) {
            return $this->fail('radmin_too_old',
                '宿主 rocareer/radmin 版本过低：CRUD 引擎 CrudService 不存在（需 v5.1.0+）',
                ['warnings' => $warnings]);
        }

        // 冲突预检（先于写迁移，失败零落盘）
        if (!$force) {
            $conflicts = [];
            foreach ($targetFiles as $file) {
                if (is_file($base . '/' . $file)) {
                    $conflicts[] = $file;
                }
            }
            if ($conflicts) {
                return $this->fail('file_conflict',
                    '目标文件已存在（已生成过/已改代码）：' . implode(', ', $conflicts),
                    ['conflicts' => $conflicts, 'warnings' => $warnings]);
            }
        }

        // 迁移落盘（同名表迁移已存在则复用，不重复写）
        $migrationFile = '';
        $migrationReused = false;
        if (!$noMigration) {
            $migrationDir = $base . '/database/migrations';
            $existing = glob($migrationDir . '/*_' . $parsed['table_name'] . '_crud.php');
            if ($existing) {
                $migrationReused = true;
                $migrationFile = str_replace($base . '/', '', $existing[0]);
            } else {
                $full = $migrationDir . '/' . $parsed['ts'] . '_' . $parsed['table_name'] . '_crud.php';
                $this->writeFile($full, $parsed['migration']);
                $migrationFile = str_replace($base . '/', '', $full);
            }
        }

        // 引擎生成
        try {
            $result = (new \app\admin\service\CrudService())->generate('update', $parsed['table'], $parsed['fields']);
        } catch (Throwable $e) {
            return $this->fail('generate_failed', 'CRUD 引擎生成失败：' . $e->getMessage(), ['warnings' => $warnings]);
        }

        $logId = ($result['crud_log'] ?? null) ? (int) $result['crud_log']->id : 0;
        return array_merge(['ok' => true], $common, [
            'dry_run' => false,
            'migration' => $migrationFile,
            'migration_reused' => $migrationReused,
            'crud_log_id' => $logId,
            'next_step' => $migrationFile !== '' && !$migrationReused
                ? 'php webman migrate:run 建表（幂等），随后重跑本命令补出代码'
                : '',
        ]);
    }

    /**
     * 运行迁移（闭环编排 --migrate 用）
     *
     * 以子进程执行 `php webman migrate:run`（cwd = 宿主根），与手工执行完全等价；
     * 不进程内直调 Phinx（避免污染常驻进程状态）。仅 CLI 一次性上下文调用。
     *
     * @return array{ok: bool, output: string}
     */
    public function migrate(): array
    {
        $base = $this->basePath();
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' webman migrate:run';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $base);
        if (!is_resource($proc)) {
            return ['ok' => false, 'output' => '无法启动 migrate:run 子进程'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $output = trim($out . ($err !== '' ? "\n" . $err : ''));
        return ['ok' => $code === 0, 'output' => $output];
    }

    protected function fail(string $code, string $message, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error_code' => $code, 'message' => $message], $extra);
    }

    protected function basePath(): string
    {
        return function_exists('base_path') ? (string) base_path() : (defined('BASE_PATH') ? BASE_PATH : (string) getcwd());
    }

    protected function writeFile(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }
}
