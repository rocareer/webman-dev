<?php

namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * CRUD 设计草稿（webman-dev，FACTORY P1）
 *
 * AI 产出的模块设计 JSON 先落本表，人工确认后才出码（红线：LLM 只产草稿）。
 * status 流转：pending（已投递）→ suggested（AI 产出、可确认）/ failed（LLM 或校验失败）
 *             → confirmed（人工确认已出码）/ rejected（人工弃用）。
 */
class CrudDesignDraft extends BaseModel
{
    protected $table = 'radmin_crud_design_draft';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    protected $casts = [
        'design' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'rounds' => 'integer',
        'admin_id' => 'integer',
        'confirmed_at' => 'integer',
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];
}
