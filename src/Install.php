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
     */
    public static function install(): void
    {
        static::installByRelation();
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
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parentDir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0777, true);
                }
            }
            // 复制目录/文件到宿主（目录递归复制）
            copy_dir(__DIR__ . "/$source", base_path() . "/$dest");
            echo "Create $dest\n";
        }
    }

    /**
     * 按 pathRelation 移除宿主项目中的插件配置（文件/链接 unlink，目录递归删除）
     */
    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path() . "/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            echo "Remove $dest\n";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }
}
