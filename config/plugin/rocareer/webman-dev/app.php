<?php

/**
 * webman-dev 插件主配置
 *
 * audit_root：工程质量审计的源码根目录（含 radmin/、ai/ 等包目录的 src 根，
 * 工作区为 <Rocareer>/src）。留空自动探测：dev/full 宿主下取 上级目录/src，
 * 兼容传工作区根（内部落到 <workspace>/src）；探测失败时后台「运行审计」会提示
 * 配置本项。
 *
 * 真源回归：随 Install.php pathRelation 落盘宿主 config/plugin/rocareer/webman-dev/。
 */

return [
    'enable' => true,
    'audit_root' => '',
];
