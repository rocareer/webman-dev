<?php
/**
 * webman-dev 命令行命令注册（webman/console 自动加载）
 *
 * 真源回归：本文件随 Install.php pathRelation 落盘宿主 config/plugin/rocareer/webman-dev/，
 * 与源码保持同步（历史上曾只存宿主侧导致新装宿主缺命令注册）。
 */

return [
    \Rocareer\WebmanDev\command\DevStatus::class,
    \Rocareer\WebmanDev\command\DevCover::class,
    \Rocareer\WebmanDev\command\DevCount::class,
    \Rocareer\WebmanDev\command\RocareerPlugin::class,
    \Rocareer\WebmanDev\command\Audit::class,
    \Rocareer\WebmanDev\command\MakePlugin::class,
    \Rocareer\WebmanDev\command\MakeCrud::class,
    \Rocareer\WebmanDev\command\TestCrudDesigner::class,
    \Rocareer\WebmanDev\command\CrudDesign::class,
];
