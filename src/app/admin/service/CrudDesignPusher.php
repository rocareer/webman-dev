<?php
/**
 * CRUD 设计草稿推送协议层（FACTORY P1）
 *
 * 事件命名 <提供方>.<领域>.<动作>（过去式）；payload 一律快照数组。
 * 推送失败由 ChannelPush 内部 no-op + 日志兜底，不影响主流程落库。
 * 硬依赖 rocareer/happ-client（webman-dev 当前 require 未含，故做软探测：
 * 未装 happ-client 时本方法 no-op，AI 生成链路的落库/CLI 消费不受影响）。
 */

namespace app\admin\service;

class CrudDesignPusher
{
    /** AI 设计草稿产出完成（成功/失败同一事件，载荷带 ok） */
    public const EVENT_SUGGESTED = 'crud.design.suggested';

    /**
     * 推送给发起的管理员（软依赖 happ-client）
     */
    public static function toAdmin(int $adminId, string $type, array $data): bool
    {
        if ($adminId <= 0 || !class_exists(\app\happclient\support\ChannelPush::class)) {
            return false;
        }
        return \app\happclient\support\ChannelPush::sendToUid(
            \app\happclient\support\Uid::admin($adminId),
            $type,
            $data
        );
    }
}
