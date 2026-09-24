<?php

namespace Rocareer\WebmanDev;

/**
 * webman-dev 安装/更新/卸载钩子（webman 基础插件，WEBMAN_PLUGIN）。
 *
 * 安装（composer require / update）：把插件接线配置
 *   config/plugin/rocareer/webman-dev/ 复制到宿主工程对应目录（pathRelation），
 *   使插件配置与命令在宿主侧生效；卸载（composer remove）时移除上述接线配置。
 *
 * 另：AI 模块设计生成（FACTORY P1）的队列消费者模板
 *   src/command/templates/consumer/CrudDesignConsumer.php → 宿主 app/queue/redis/，
 *   由 webman/redis-queue 消费进程自动加载（consumer_dir = app/queue/redis）。
 */
class Install
{
    /** 接线配置安装清单（相对项目根路径 => 投放时 md5；卸载判据的唯一依据，见 syncManifest） */
    protected const INSTALL_MANIFEST = 'runtime/rocareer-webman-dev-install-manifest.json';

    const WEBMAN_PLUGIN = true;

    /**
     * 需要落盘到宿主项目的目录/文件（源 => 目标，相对项目根）
     */
    protected static $pathRelation = [
        'config/plugin/rocareer/webman-dev' => 'config/plugin/rocareer/webman-dev',
    ];

    /**
     * 落盘到宿主的队列消费者模板（源相对包根 => 宿主目标相对项目根）
     */
    protected static $consumerFiles = [
        'src/command/templates/consumer/CrudDesignConsumer.php' => 'app/queue/redis/CrudDesignConsumer.php',
    ];

    /**
     * 安装钩子：复制接线配置到宿主项目
     *
     * @param bool $isFirst 是否首次安装（composer require 时为 true，update 回退时为 false）
     */
    public static function install($isFirst = true): void
    {
        static::installByRelation($isFirst);
        static::installConsumers($isFirst);
    }

    /**
     * 更新钩子：补齐缺失接线配置（升级专属钩子，官方 Plugin::update 调用）
     */
    public static function update(): void
    {
        static::installByRelation(false);
        static::installConsumers(false);
    }

    /**
     * 卸载钩子：移除复制到宿主项目的接线配置
     */
    public static function uninstall(): void
    {
        static::uninstallByRelation();
        static::uninstallConsumers();
    }

    /**
     * 复制队列消费者模板到宿主（**永不覆盖宿主已存在文件**，缺文件时补齐）。
     * 不看 $isFirst：webman composer 安装器在 composer update 时同样走 install(isFirst=true)
     * （2026-09-22 实证：v3.28.0 升级把 Rolling 宿主带 QueueConsume 记账的消费类覆盖回了裸模板），
     * 「仅升级路径跳过」的守卫形同虚设——无条件跳过已存在文件才是真「缺失才写」。
     */
    protected static function installConsumers(bool $isFirst): void
    {
        foreach (static::$consumerFiles as $source => $dest) {
            $destPath = base_path() . '/' . $dest;
            if (is_file($destPath)) {
                continue;
            }
            $sourcePath = dirname(__DIR__) . '/' . $source;
            if (!is_file($sourcePath)) {
                continue;
            }
            if (!is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0777, true);
            }
            copy($sourcePath, $destPath);
            echo "Copy $dest\n";
        }
    }

    /**
     * 移除宿主侧的队列消费者模板
     */
    protected static function uninstallConsumers(): void
    {
        foreach (static::$consumerFiles as $source => $dest) {
            $path = base_path() . '/' . $dest;
            if (is_file($path) && !is_link($path)) {
                unlink($path);
                echo "Remove $dest\n";
            }
        }
    }

    /**
     * 按 pathRelation 将插件配置复制到宿主项目（目标父目录不存在时自动创建）
     *
     * 行为：**目标已存在（目录/文件）一律跳过，缺失才补**——app.php 含 audit_root 用户可配项，
     * 不覆盖宿主已有配置（与 radmin/ai 先例一致）。不看 $isFirst：webman composer 安装器在
     * composer update 时同样走 install(isFirst=true)，按 flag 判「首装」会整目录重拷覆盖宿主配置。
     */
    protected static function installByRelation(bool $isFirst): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parentDir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0777, true);
                }
            }
            $sourcePath = dirname(__DIR__) . '/' . $source;
            $destPath = base_path() . '/' . $dest;

            if (is_dir($sourcePath)) {
                if (!is_dir($destPath)) {
                    static::copyDir($sourcePath, $destPath);
                    echo "Copy $dest\n";
                } else {
                    $copied = static::copyMissingFiles($sourcePath, $destPath);
                    if ($copied > 0) {
                        echo "Copy $dest ({$copied} new file(s))\n";
                    }
                }
            } elseif (is_file($sourcePath)) {
                if (!is_file($destPath)) {
                    if (!is_dir(dirname($destPath))) {
                        mkdir(dirname($destPath), 0777, true);
                    }
                    copy($sourcePath, $destPath);
                    echo "Copy $dest\n";
                }
            }
        }
        static::syncManifest();
    }

    /**
     * 按 pathRelation 移除宿主项目中的插件配置（文件 unlink，目录递归删除）
     */
    /**
     * 卸载接线关系：**逐文件**按安装清单判定，绝不整目录删（守卫口径见 syncManifest 上方注释）。
     */
    protected static function uninstallByRelation(): void
    {
        $manifest = static::readManifest();
        foreach (array_reverse(array_values(static::$pathRelation)) as $dest) {
            $path = base_path() . '/' . $dest;
            if (is_dir($path) && !is_link($path)) {
                static::removeConfigDir($dest, $path, $manifest);
            } elseif (is_file($path)) {
                if (($manifest[$dest] ?? null) === md5_file($path)) {
                    unlink($path);
                    unset($manifest[$dest]);
                    echo "Remove $dest\n";
                } else {
                    echo "Skip remove $dest (modified by project, kept)\n";
                }
            }
        }
        static::saveManifest($manifest);
    }

    /**
     * 递归复制目录
     */
    protected static function copyDir(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0777, true);
        }
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $source . '/' . $item;
            $destPath = $dest . '/' . $item;
            if (is_dir($srcPath)) {
                static::copyDir($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /**
     * 递归删除目录
     */
    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? static::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== 接线配置卸载守卫（2026-09-24 立；与 rocareer/queue v1.8.7 同源实现）====================
    // 病灶：uninstallByRelation 原为「整目录删」——宿主在 config/plugin/rocareer/<包>/ 里的定制
    // 随包资产一起消失，紧随的 install 见目录不存在又全量铺包默认 ⇒ 宿主策略静默丢失
    // （2026-09-24 queue 包实测事故：19 条队列塌进一个组、LLM 契约闸拒绝 2454 条作业）。
    // 口径（Rocareer docs/install-standard.md §四.3「必须清单精确卸载，绝不整目录删」）：
    //   安装写清单（相对项目根路径 => 投放时 md5，只登记与包内逐字节一致的文件）；
    //   卸载逐文件判 md5——宿主分叉与宿主自有文件一律保留并点名；只回收空目录；无清单则整目录按「宿主拥有」处理。

    /**
     * 写安装清单：按 pathRelation 的目标段重建条目（先清该段旧条目，再加当前一致项）。
     *
     * 只登记「与包内逐字节一致」的文件 ⇒ 宿主分叉与宿主自有文件天然不入单（卸载时保留，保守方向）。
     */
    protected static function syncManifest(): void
    {
        $manifest = static::readManifest();
        foreach (static::$pathRelation as $source => $dest) {
            $prefix = $dest . '/';
            foreach (array_keys($manifest) as $rel) {
                if ($rel === $dest || str_starts_with($rel, $prefix)) {
                    unset($manifest[$rel]);
                }
            }
            $sourcePath = dirname(__DIR__) . '/' . $source;
            $destPath = base_path() . '/' . $dest;
            if (is_dir($sourcePath) && is_dir($destPath)) {
                foreach (static::filesUnder($sourcePath) as $rel) {
                    $destFile = $destPath . '/' . $rel;
                    if (!is_file($destFile)) {
                        continue;
                    }
                    $md5 = md5_file($destFile);
                    if ($md5 !== false && $md5 === md5_file($sourcePath . '/' . $rel)) {
                        $manifest[$prefix . $rel] = $md5;
                    }
                }
            } elseif (is_file($sourcePath) && is_file($destPath)) {
                $md5 = md5_file($destPath);
                if ($md5 !== false && $md5 === md5_file($sourcePath)) {
                    $manifest[$dest] = $md5;
                }
            }
        }
        static::saveManifest($manifest);
    }

    /** @return array<string,string> 相对项目根路径 => 投放时 md5（无清单/损坏 ⇒ 空数组，调用方按「宿主拥有」处理） */
    protected static function readManifest(): array
    {
        $file = base_path() . '/' . static::INSTALL_MANIFEST;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $rel => $md5) {
            // 安全：仅接受项目根下的普通相对路径
            if (is_string($rel) && is_string($md5) && $rel !== ''
                && !str_starts_with($rel, '/') && !str_contains($rel, '..')) {
                $out[$rel] = $md5;
            }
        }
        return $out;
    }

    /** @param array<string,string> $manifest */
    protected static function saveManifest(array $manifest): void
    {
        $file = base_path() . '/' . static::INSTALL_MANIFEST;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        ksort($manifest);
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** @return list<string> 目录下所有文件的相对路径（不含目录项） */
    protected static function filesUnder(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                $out[] = $iterator->getSubPathName();
            }
        }
        return $out;
    }

    /** 自底向上回收空目录（只 rmdir 空壳；非空即停，绝不递归删内容） */
    protected static function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink() && static::isDirEmpty($item->getPathname())) {
                rmdir($item->getPathname());
            }
        }
        if (static::isDirEmpty($dir)) {
            rmdir($dir);
        }
    }

    /** 目录是否为空（不含 `.` / `..`） */
    protected static function isDirEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * 按清单精确移除一个接线配置目录（只删包投放且未被宿主改动的文件）。
     *
     * @param array<string,string> $manifest 全量清单（按引用更新：已删条目出单）
     */
    protected static function removeConfigDir(string $dest, string $path, array &$manifest): void
    {
        $prefix = $dest . '/';
        $entries = [];
        foreach ($manifest as $rel => $md5) {
            if (str_starts_with($rel, $prefix)) {
                $entries[substr($rel, strlen($prefix))] = $md5;
            }
        }
        if ($entries === []) {
            // 无清单条目：整目录按「宿主拥有」处理（首装早于本版 / 清单被清 / 目录全是宿主自建文件）
            echo "Skip remove $dest (no install manifest entry, keep existing files)\n";
            return;
        }
        $removed = 0;
        $kept = [];
        foreach ($entries as $rel => $md5) {
            $file = $path . '/' . $rel;
            if (!is_file($file)) {
                unset($manifest[$prefix . $rel]);
                continue;
            }
            if (md5_file($file) === $md5) {
                unlink($file);
                unset($manifest[$prefix . $rel]);
                $removed++;
            } else {
                $kept[] = $rel;   // 宿主分叉：留在原处，且出单（下次卸载不再拿旧哈希误判）
            }
        }
        // 未入清单的宿主自有文件一律保留，点名便于运维对账
        $hostOwned = array_values(array_diff(static::filesUnder($path), array_keys($entries)));
        static::pruneEmptyDirs($path);
        echo "Remove $dest ({$removed} file(s))\n";
        if ($kept !== []) {
            echo "Skip remove $dest (modified by project, kept): " . implode(', ', $kept) . "\n";
        }
        if ($hostOwned !== []) {
            echo "Skip remove $dest (host-owned, kept): " . implode(', ', $hostOwned) . "\n";
        }
    }

    /** 补齐源目录中存在而目标缺失的文件（升级路径；返回补拷数量）。缺失才写，已存在一律不覆盖（宿主定制优先）。 */
    protected static function copyMissingFiles(string $source, string $dest): int
    {
        $copied = 0;
        if (!is_dir($source)) {
            return $copied;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }
            if (!is_file($target)) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                copy($item->getPathname(), $target);
                $copied++;
            }
        }
        return $copied;
    }
}
