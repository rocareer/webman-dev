<?php

namespace Rocareer\WebmanDev;

/**
 * webman-dev 安装/更新/卸载钩子（webman 基础插件，WEBMAN_PLUGIN）。
 *
 * 安装（composer require / update）：把插件接线配置
 *   config/plugin/rocareer/webman-dev/ 复制到宿主工程对应目录（pathRelation），
 *   使插件配置与命令在宿主侧生效；卸载（composer remove）时移除上述接线配置。
 */
class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * 需要落盘到宿主项目的目录/文件（源 => 目标，相对项目根）
     */
    protected static $pathRelation = [
        'config/plugin/rocareer/webman-dev' => 'config/plugin/rocareer/webman-dev',
    ];

    /**
     * 安装钩子：复制接线配置到宿主项目
     *
     * @param bool $isFirst 是否首次安装（composer require 时为 true，update 回退时为 false）
     */
    public static function install($isFirst = true): void
    {
        static::installByRelation($isFirst);
    }

    /**
     * 更新钩子：补齐缺失接线配置（升级专属钩子，官方 Plugin::update 调用）
     */
    public static function update(): void
    {
        static::installByRelation(false);
    }

    /**
     * 卸载钩子：移除复制到宿主项目的接线配置
     */
    public static function uninstall(): void
    {
        static::uninstallByRelation();
    }

    /**
     * 按 pathRelation 将插件配置复制到宿主项目（目标父目录不存在时自动创建）
     *
     * 行为：首次安装全量拷贝；更新仅补齐缺失项——app.php 含 audit_root 用户可配项，
     * 不覆盖宿主已有配置（与 radmin/ai 先例一致）。
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
                if ($isFirst || !is_dir($destPath)) {
                    static::copyDir($sourcePath, $destPath);
                    echo "Copy $dest\n";
                }
            } elseif (is_file($sourcePath)) {
                if ($isFirst || !is_file($destPath)) {
                    if (!is_dir(dirname($destPath))) {
                        mkdir(dirname($destPath), 0777, true);
                    }
                    copy($sourcePath, $destPath);
                    echo "Copy $dest\n";
                }
            }
        }
    }

    /**
     * 按 pathRelation 移除宿主项目中的插件配置（文件 unlink，目录递归删除）
     */
    protected static function uninstallByRelation(): void
    {
        foreach (array_reverse(static::$pathRelation) as $source => $dest) {
            $path = base_path() . '/' . $dest;
            if (is_dir($path) && !is_link($path)) {
                static::removeDir($path);
                echo "Remove $dest\n";
            } elseif (is_file($path)) {
                unlink($path);
                echo "Remove $dest\n";
            }
        }
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
}
