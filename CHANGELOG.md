# Changelog

## 未发布（Unreleased）

### 许可与版权

- 许可证由开源协议改为 proprietary（商业/内部专有），不适用任何开源许可证；LICENSE 文件同步替换为 Rocareer 专有许可文本。
- 版权声明统一为：Copyright (c) Rocareer Team. All rights reserved.；作者：albert@rocareer.com。

## v3.1.0 - 2026-10-28

### 新增「工程质量审计」后台管理页（开发运维）

- 审计引擎抽成服务 `app\admin\service\AuditService`：CLI `rocareer:audit` 与后台管理页共用同一套六类规则实现（php -l / 控制器规范 / 权限节点 routePath / 迁移时间戳 / 残留扫描 / 版本同步），规则结果统一为 pass/skipped/count/issues/note 结构。
- 新增三张表（迁移 `20261028120000_radmin_webman_dev_audit_page`，幂等）：
  - `radmin_dev_audit_rule` 审计规则（code 与引擎内置规则对应，停用即不参与运行）
  - `radmin_dev_audit_project` 审计项目（name 为 src 根下包目录名，带最近一轮快照 last_run_at/last_issue_count/last_fail_rules）
  - `radmin_dev_audit_result` 审计结果明细（每项目每规则每轮一行，detail 为问题明细 JSON）
- 新增三个后台页面，挂到「开发和调试」（name=dev）菜单下：
  - 审计项目（audit/auditproject）：项目 CRUD + 一键「运行审计」（全部/单项目）+ 顶部统计条（项目/规则数、最近一轮问题数、通过/未通过项目数）
  - 审计规则（audit/auditrule）：规则 CRUD + 启停
  - 审计结果（audit/auditresult）：结果明细列表 + 问题详情弹窗 + 审计轮次/项目/规则/结果筛选
- 根目录探测修复：`--root`/自动探测兼容 src 根（<workspace>/src）与工作区根（<workspace>）两种布局，内部统一落到 src 根；版本同步规则按 src 根自动定位 `../dev/full/composer.json`（此前版查不到 dev 钉版导致该检查永远 SKIP）。
- 插件配置新增 `audit_root`（留空自动探测；非 dev 布局可显式指定源码根）。
- php -l 由逐文件子进程改为按包批量子进程（`find | xargs php -l` 单次调用），后台运行审计耗时可控。

### 兼容性

- composer autoload 新增 `"app\\": "src/app"`（后台控制器/模型/服务挂载）；新增依赖 `rocareer/radmin: ^3.1`。
- 需要 `migrate:run` 建表并注册菜单权限；前端页面同步进 radmin web 构建树（`src/radmin/web/src/views/backend/audit/`）后需重建前端。

## v3.1.0 - 2026-08-27

### MCP 工程质量审计工具自动注册（feat）

- 本包新增 config/plugin/rocareer/webman-dev/event.php 监听 **mcp.collections.register**：宿主装了
  rocareer/mcp 时自动注册 MCP 工具集合 `app\mcp\collection\AuditCollection`（子端点 /mcp/dev），
  提供 **quality_audit** 工具——复用 AuditService 六类规则（php 语法/控制器规范/权限节点/迁移时间戳/
  残留扫描/版本同步），detail=false 返回摘要、true 附带问题明细；未装 mcp 时无任何副作用。
- **AuditService**：php_syntax 规则协程化——webman 常驻进程内改走 proc_open 非阻塞轮询
  （Workerman\Timer::sleep 让出事件循环），CLI 仍回退同步 shell_exec；新增 DEFAULT_PACKAGES 常量
  （CLI rocareer:audit 与 MCP 工具共用，Audit 命令改为引用该常量）。
- **修复 rootPath 自动探测**：dev/full 宿主（workspace/dev/<host>）此前按 dirname(base_path)+/src 探测成
  workspace/dev/src（不存在）导致探测失败，现加入「上上级/src」候选（workspace/src）并保留旧候选兼容。

### 后台页落地验证修复（fix）

- **模型时间戳**：DevAuditRule/DevAuditProject/DevAuditResult 增加 `$dateFormat = false`（think-orm v4 会把
  int 时间戳自动格式化成 'Y-m-d H:i:s' 字符串，导致列表时间列显示错乱；与 agent 包同款约定）。
- **run 按项目筛选**：`ids` 参数显式归一化（数组/逗号串均可）——webman `post()` 不支持 `/a` 修饰符，
  此前按 ids 运行审计实际会跑全部项目。
- **check 返回类型**：六个 check 方法返回类型由 `array` 放宽为 `?array`（跳过场景返回 null），
  否则运行审计在规则跳过时直接抛「Return value must be of type array」。
- **版本同步规则**：取首个「已发布」版本小节（跳过 `未发布/Unreleased` 段），避免把未发布段当版本号误报。

## v3.0.0 - 2026-08-30

### 新增 rocareer:audit 代码规范审计命令

- 控制器规范检查：继承 Backend、: Response 签名（?Response/return null 报错）、initialize 调 parent、public 方法返回类型
- 权限节点与 routePath 匹配（核心）：按 radmin Request::controller() 规则计算每个控制器方法的 routePath
  （类名末两段小写 + '/' + 方法名小写，全小写无连字符），与迁移中注册的按钮名比对，
  缺失/错名/孤儿按钮全部报出（2026-08-30 深度审计发现 ai/channel/agent 等包连字符按钮对非超管 401，即此检查修复）
- 迁移时间戳查重（Phinx 全局排序，撞号致全家桶 migrate 无法执行）
- 残留扫描：CRUD 脚手架死代码（Test 控制器/模型/验证器）、TODO/FIXME 计数
- 版本同步：CHANGELOG 头部版本 vs dev/full composer.json path 钉版
- 用法：php webman rocareer:audit [--root=工作区根] [--pkg=ai]

### 新增 rocareer:make-plugin 插件脚手架命令

- 一键生成符合 radmin 全家桶规范的插件骨架（12 个模板，占位符 {{NAME}}/{{UC}}/{{LCC}}/{{TITLE}}/{{DESC}}/{{TS}}/{{YEAR}}）：
  composer.json / CHANGELOG / README / .gitignore / config/plugin/rocareer/<name>/ /
  幂等初始迁移（建表+菜单权限，按钮名自动对齐 routePath）/ Install.php / <name>_config helper /
  Backend 五件套示例控制器 / BaseModel 示例 / 服务层示例
- 用法：php webman rocareer:make-plugin <name> --title=中文名 [--description=...] [--out=路径]

## v2.0.1

- 依赖与同步修复（webman-filesystem 版本要求、命名空间转换、插件路径配置）

## v2.0.0

- DevStatus/DevCover/DevCount/RocareerPlugin 命令体系
