<?php

/**
 * webman-dev 插件主配置
 *
 * audit_root：工程质量审计的源码根目录（含 radmin/、ai/ 等包目录的 src 根，
 * 工作区为 <Rocareer>/src）。留空自动探测：dev/full 宿主下取 上级目录/src，
 * 兼容传工作区根（内部落到 <workspace>/src）；探测失败时后台「运行审计」会提示配置本项。
 *
 * settle_queue：CRUD 设计收尾回放队列名（收尾出组律，2026-10-10）。留空 `''` = 收尾内联逐字
 * （零行为变化）；宿主配值（建议 `env('CRUD_SETTLE_QUEUE', '')`）后，回调档 LLM 腿完成即出组，
 * 落草稿/推送由该队列消费者经 `CrudDesignAgentService::settleApply()` 回放。
 */

return [
    'enable' => true,
    'audit_root' => '',
    'settle_queue' => '',
];