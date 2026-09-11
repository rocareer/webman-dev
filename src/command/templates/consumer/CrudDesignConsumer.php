<?php
/**
 * CRUD 设计草稿生成消费者（FACTORY P1，软依赖 rocareer/agent）
 *
 * 由 Install 复制到项目 app/queue/redis/CrudDesignConsumer.php，
 * 被 webman/redis-queue 自带消费进程自动加载（consumer_dir = app/queue/redis）。
 * 建议逻辑委托 webman-dev 包 CrudDesignAgentService（需求 → LLM 设计 JSON →
 * 净化/校验/自修复 → 落草稿 → happ 推送），未安装 agent 包时任务标记失败。
 */

namespace app\queue\redis;

use app\admin\service\CrudDesignAgentService;
use Webman\RedisQueue\Consumer;

class CrudDesignConsumer implements Consumer
{
    public $connection = 'default';

    public $queue = 'crud-design';

    public function consume($data): void
    {
        $requestId = (string) ($data['request_id'] ?? '');
        if ($requestId === '') {
            return;
        }
        (new CrudDesignAgentService())->execute($requestId);
    }
}
