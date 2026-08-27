# rocareer/webman-dev

Radmin 全家桶开发工具包：代码规范审计 + 插件脚手架 +「工程质量审计」后台管理页。

## 安装

dev/full/composer.json 注册 path 仓库并钉版（versions: rocareer/webman-dev = 3.1.0），
然后 composer update --no-dev；`php webman migrate:run` 建表并注册菜单（开发运维 → 审计项目/审计规则/审计结果）。
后台页面在 src/radmin/web 构建树中的 `web/src/views/backend/audit/`（由本包 web/ 同步，需重建前端）。

## 命令

### rocareer:audit — 基础设施包代码规范审计

    php webman rocareer:audit              # 审计全部包（自动探测源码根）
    php webman rocareer:audit --pkg=ai     # 只审计 ai
    php webman rocareer:audit --root=/path/to/src

检查项：php -l、控制器规范（Backend / : Response / initialize / 注释）、
权限按钮 name 与 routePath 匹配、迁移时间戳查重、脚手架残留、版本钉版同步、
前端页面规范（web_page：Vue 页面模板一致性——禁止自创依赖注入/裸 axios//src/ 导入、
baTable 体系页面必须经 baTable、弹窗提交走 onSubmit；radmin 同步树跳过）。
任一 FAIL 时 exit code 非 0，可用于 CI。规则实现在 `app\admin\service\AuditService`，
与后台管理页共用同一引擎；`--root` 接受含 radmin 的 src 根或工作区根（内部落到 src）。

## MCP 工程质量审计工具（自动注册）

宿主装了 rocareer/mcp 后，本包自动注册 MCP 工具集合（事件 mcp.collections.register），
外部 MCP 客户端（DSH / Claude Desktop 等）可直接调用 **quality_audit**：

- 参数：`root`（包目录根，缺省自动探测）、`pkg`（单包，缺省全部默认包）、`codes`（规则子集）、`detail`（是否附明细）；
- 返回：包/规则级摘要（通过/失败/跳过/问题数）+ 可选问题明细；
- 子端点 `/mcp/dev` 只服务本集合工具；重量级操作（秒级，低频使用）；
- 工具与 CLI/后台共用同一 AuditService 引擎，php -l 在常驻进程内非阻塞执行（不阻塞 MCP 端口其他请求）。

## 后台管理页（开发运维 → 工程质量审计）

- 审计项目：项目 CRUD + 一键运行审计（全部/单项目）+ 最近一轮问题数/未通过规则 + 顶部统计条
- 审计规则：规则 CRUD + 启停（停用不参与运行）
- 审计结果：结果明细 + 问题详情 + 轮次/项目/规则/结果筛选
- 源码根定位：插件配置 `audit_root`（留空自动探测：dev/full 宿主取 上级目录/src）；探测失败时页面会提示配置。

## 命令：rocareer:make-plugin — 插件脚手架

    php webman rocareer:make-plugin dev --title=演示 --description="..." [--out=/tmp/dev]

生成标准插件骨架后按提示接入 dev 即可。
