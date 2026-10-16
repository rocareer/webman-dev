<?php

/**
 * webman-dev 插件主配置
 *
 * audit_root：工程质量审计的源码根目录（含 radmin/、ai/ 等包目录的 src 根，
 * 工作区为 <Rocareer>/src）。留空自动探测：dev/full 宿主下取 上级目录/src，
 * 兼容传工作区根（内部落到 <workspace>/src）；探测失败时后台「运行审计」会提示
 * 配置本项。
 *
 * audit_exempt_path_segments：死类检测的**路径段豁免**清单（缺省空 = 行为不变）。用途：某些目录的类
 * 按 **DB 行**注册（FQCN 字符串运行时解析），字符不落文件 ⇒ 静态扫描原理上扫不到，逐件补
 * `@audit-ignore dead_code` 属可复发形态。命中规则 = `str_contains($rel, $段)`（段含首尾斜杠时
 * 为路径段精确匹配，如 `'/adv/'`）。**宿主按需配置**（工作区是「策略」，包只提供「机制」）。
 *
 * 真源回归：随 Install.php pathRelation 落盘宿主 config/plugin/rocareer/webman-dev/。
 */

return [
    'enable' => true,
    'audit_root' => '',
    'audit_exempt_path_segments' => [],
];
