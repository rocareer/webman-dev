# Changelog

## [v3.11.8] - 2026-08-31

### 修复：orm_migrated 审计补 Db::connect 规则 + DevCover 反向映射清理

- AuditService `orm_migrated` 门禁新增 `Db::connect(` 扫描（think 门面方法，全域收口防回归）；
- `dev:cover` 删除 `= config('database'` → `= config('think-orm'` 反向映射
  （v4 起配置键为 database，该映射会生成错误代码）；docblock 注释同步收敛。



## [v3.11.7] - 2026-08-31

### 修复：DevCover 注释收敛（support\Db 门面）

- `dev:cover` 命令文档注释 `support\orm\Db` → `support\Db`（radmin 已移除 support\orm\Db）。



## [v3.11.6] - 2026-08-31

### 修复：orm_migrated 审计扫描扩展至 database/ 迁移目录

- AuditService 的 `orm_migrated` 规则扫描范围从 `src/` 扩展到 `src/ + database/`
  （迁移同样禁 think 语义残留，2026-08-31 曾漏 version202 的 Db::startTrans）。



## [v3.11.5] - 2026-08-31

### 修复：关键字搜索收敛到 radmin 全局 keyword_like()

- AuditResult 1 处手写 where 闭包样板；多字段 like 搜索统一走 keyword_like($query, [$fields], $keyword)，禁止手写 where 闭包样板。

## [v3.11.4] - 2026-08-31

### 修复：JSON 编码收敛到 radmin 全局 json_unicode()

- json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) 手写组合 → json_unicode()（AuditProject 1 处）；统一默认 flag，禁止手写组合。

## [v3.11.3] - 2026-08-31

### 修复：audit async_blocking 规则文案与豁免同步自研 Fiber 收敛

- 阻塞休眠提示由「Fiber::sleep / Timer::sleep」改为官方 `Workerman\Timer::sleep` 唯一；
- 移除 `Fiber.php` 文件豁免（自研 Fiber 类已删除，见 ai v2.4.5）。

## [v3.11.2] - 2026-08-31

### 修复：分页参数收敛到 radmin 全局函数

- AuditResult 控制器的 `max(1, min(100, ...))` 手写 clamp 与 keyword 兼容链 → `clamp_limit()`/`clamp_page()`/`request_keyword()`；
- 真源唯一：分页与搜索参数统一走 radmin 全局函数。

## [v3.11.1] - 2026-08-30

### 修复：审计项目页自定义表头按钮样式对齐 BuildAdmin 标准

- `audit/projects` 表格头「运行全部审计」按钮：补页面 scoped 间距兜底（TableHeader
  组件的 `.mlr-12 + .el-button` 为组件内 scoped 规则，作用不到页面 slot 传入的按钮，
  需在页面侧补 `.table-header-operate { margin-left: 12px }`）；
- 按钮 Icon 显式 `color="#ffffff"`（Icon 默认黑色，彩色按钮上不协调，对齐 crud/log 先例）。

## [v3.11.0] - 2026-08-30

### 事件规范增强：禁止 emit、一律 dispatch（不吞异常）

- `event_standard` 规则新增「禁止 `Event::emit`」检查：emit 吞监听器异常掩盖故障，
  一律用 `Event::dispatch`（异常上抛、监听器 Bug 可见）；事件名提取正则兼容 dispatch；
- 发射点/散落 `Event::on()` 扫描跳过注释行（修复注释掉的 `Event::dispatch` 误报）；
- 规则描述同步 dispatch 优先语义；迁移种子 `20261230000000` 描述文本同步。

## [v3.10.0] - 2026-08-30

### 新增审计规则：事件规范（event_standard）

- `rocareer:audit` 新增 `event_standard` 规则：webman/event 使用规范门禁
  （依据 `docs/webman-event-standard.md` 沉淀规则）：
  - 事件名格式：`Event::emit/on` 事件名必须「提供方.领域.动作」全小写点分
    （禁驼峰/连字符/下划线分隔/无前缀裸名）；
  - 业务代码禁止散落 `Event::on()`（监听器集中 `config/plugin/*/event.php` 或
    `config/event.php` 声明，唯一例外 radmin `EventRegister` 内置 member.*）；
  - 孤儿事件检测：静态 `Event::emit` 事件名在全工作区监听器注册表
    （本包/跨包 event.php、radmin EventRegister、dev 宿主 config/event.php、
    前缀通配如 happ.message.* 均计入）中无任何监听 = 发射即空转，报出并提示
    纯日志直写日志；
  - 监听器方法签名：`app/listener` 下 `on*` 方法必须 `(array $data): void`；
  - 文件标注 `@audit-ignore event_standard` 显式豁免。
- 幂等种子迁移 `20261230000000_radmin_webman_dev_audit_event_standard_rule.php`
  （后台规则列表同步）。

## [Unreleased] - 

### 清理

- 前端 `web/src/views/backend/audit/rules/index.vue` 移除未使用导入 ref。

## [vv3.9.0] - 2026-12-06

### 新增审计规则：ORM 迁移门禁（orm_migrated）

- `rocareer:audit` 新增 `orm_migrated` 规则：src 内禁止 think-orm 类引用
  （think\facade\Db / think\db\exception / think\model\relation / think\Paginator /
  think\File / think\Exception）与 `config('think-orm...')` 调用、composer 依赖 webman/think-orm；
  白名单保留 think-validate / think-helper / think-container 类；`@audit-ignore orm_migrated` 显式豁免
- 幂等种子迁移 `20261206060000_radmin_webman_dev_audit_orm_migrated_rule.php`（后台规则列表同步）
- RocareerPlugin `think\File` → `SplFileInfo`（think-orm 卸载适配）；DevCover 映射目标改
  `support\orm\Db` 并标注豁免；18 包全量扫描 0 问题
- 随 radmin v4.0.0 批次发版

## [v3.8.1] - 2026-12-06

### 修复：审计规则种子迁移排序（全 PG 迁移通道前置）
- `radmin_webman_dev_audit_dto_contract_rule` 文件名时间戳 20260828150000 → 20261028125000：
  此前先于建表迁移 `radmin_webman_dev_audit_page`（20261028120000）执行，全新库
  （MySQL 或 PG）会因 `ra_radmin_dev_audit_rule` 不存在而失败；改名后置于建表之后、
  其余规则种子之前。老库 phinxlog 已记录旧版本号，重跑时按 name 去重幂等，无重复数据。

## [v3.8.0] - 2026-12-04

### 新增：全域 LLM 门禁审计规则（llm_gate，随 rocareer/agent v2.0）

- 依据「全域 LLM 业务交付给智能体插件管理、无智能体不开工」（agent v2.0 基础设施升级），
  新增 `llm_gate` 审计规则：
  - 扫描各包 `src/app` 下**直接实例化 `AiRouterService`**（`new AiRouterService(`）的业务代码 =
    绕过智能体门禁，报违规并提示改经 agent 包 `AgentGateway`；
  - **豁免**：ai（底层提供者）与 agent（网关实现）两包跳过；文件标注 `@audit-ignore llm_gate`
    显式豁免（如 ai 调试/开放 API 运维接口）；仅 use/常量引用（`AiRouterService::BIZ_*` 等）不报。
- 幂等迁移 `20261204000000` 种子规则进 `radmin_dev_audit_rule`。
- MCP `quality_audit` 工具 `codes` 参数说明同步补齐 `llm_gate` 规则码。

## [v3.7.1] - 2026-08-28

### 修复：dto_contract 豁免标准分页信封

- `dto_contract` 规则修正误报：标准分页信封 `{list,total,page,limit}` 为平台级通用契约
  （各列表接口统一返回），不属于模块契约，豁免不再提示；模块契约落在列表项形状（`items[]` 项检查覆盖）。
- 配套：asset 列表信封（/asset/api/assets）不再报；asset 分类项/资源项按试点 DTO 化后资产包 dto_contract 转绿。

## [v3.7.0] - 2026-08-28

### 新增：DTO 分层规范审计规则（dto_contract）

- 依据「编码规范 · DTO 分层规范」（AGENTS.md，2026-08-28 全域排查沉淀），新增 `dto_contract` 审计规则：
  - **公开 API 契约门禁**：非 admin 的公开 API 控制器内 `$this->success('', [...])` 手拼多字段数组
    或 `$items[] = [...]` 手拼列表项 = 契约未固化，提示引入 `app/<模块>/dto/` typed DTO 或 Model accessor；
  - **过度设计门禁**：`dto/` 目录内纯搬运类（`toArray()` 原样返回构造入参、无字段整形/强转/脱敏）= 过度设计，直接用数组；
  - **命名门禁**：目录统一用 `dto`，发现 `data/` 命名的数据契约目录提示改名（`data` 与"数据/数据库"歧义）。
  - 文件标注 `@audit-ignore dto_contract` 显式豁免。
- 幂等迁移 `20260828150000_radmin_webman_dev_audit_dto_contract_rule` 种子规则进 `radmin_dev_audit_rule`。
- MCP `quality_audit` 工具 `codes` 参数说明同步补齐全部规则码。

## [v3.6.1] - 2026-12-03

### 修复

- **v3.6.0 迁移兼容修复**：`20261203130000` 迁移改用 `execute($sql, $params)` PDO 参数绑定
  更新规则说明——原 `getAdapter()->quote()` 在 Phinx `TimedOutputAdapter` 包装下不存在，
  导致 `migrate:run` 报「Call to undefined method TimedOutputAdapter::quote()」；现可正常
  在 cc-knowledge 等宿主执行。幂等逻辑不变（description 一致时跳过）。

## [v3.6.0] - 2026-12-03

### 新增：前端按钮样式门禁（web_page 规则第 6 项）

- web_page 规则新增检查：**TableHeader 顶部自定义按钮必须使用标准样式类 `table-header-operate`**
  （与 refresh/add/delete 等内置按钮同款，禁止自创类名/裸 `<i>` 图标/`&nbsp;` 拼接）。
  2026-08-28 实战：审计项目页自创 `table-header-audit-run` 非标样式、diancan 桌台页裸图标——
  全域排查后两处均已修复；规则作为防回潮门禁，静态扫描各包 `web/src/views/backend`。
- 迁移 `20261203130000_radmin_webman_dev_audit_web_page_button_rule`（幂等）更新
  web_page 规则说明，后台「审计规则」页描述与引擎元数据同步，需 `migrate:run`。

## [v3.5.1] - 2026-12-03

### 修复

- **审计运行超时与文案**：审计项目页「运行全部审计」请求超时 600s（全量项目约 1 分钟，
  默认 30s 会超时中断）；提示文案改为「全量项目约需 1 分钟左右，期间请勿关闭页面/重复点击」；
  运行按钮样式与表头其它操作按钮对齐。

## [v3.5.0] - 2026-12-03

### 审计菜单归入「工程质量审计」目录

- 三个审计页面（审计项目/审计规则/审计结果）原先平铺在「开发和调试」下，现归入
  「开发和调试 → 工程质量审计」二级目录，侧边栏结构更清晰；页面与按钮权限
  （audit/*）不变，前端路由路径不变（目录仅分组，不进 URL）。迁移幂等，已装库
  `migrate:run` 后自动重挂，无需手工改菜单。

## [v3.4.1] - 2026-10-20

### 修复

- **无 mcp 宿主崩溃**：`config/plugin/rocareer/webman-dev/event.php` 的
  `mcp.collections.register` 监听在未安装 rocareer/mcp 的宿主上会因类加载触发 Fatal
  （AuditCollection implements mcp 接口，interface 不存在）；改为 `interface_exists`
  守卫后仅在有 mcp 的宿主注册监听（实证：dev/diancan 仅装 radmin+diancan 的宿主可正常
  migrate:run / start）。

## [v3.4.0] - 2026-09-01

### 规则引擎升级（rocareer:audit 精度修复，消除全量误报）

- **死类检测导入感知**：引用扫描支持文件级 `use X / use X as Y` 导入表与同包同命名空间裸短名
  （`Message::`、`DeepSeekDriver::class`、`new ChannelModel()`），修复 AiChannel/Memory/happ Message/
  ai 四驱动等"实际在用被判死"的误报；`templates/` 复制模板计入引用来源（宿主安装后即运行的真实代码，
  修复 OIDC 邮件/短信服务误报）。
- **SDK 公共 API 豁免**：无 `src/app/admin` 的包（channel-client/oidc-client 等纯 SDK）类为外部宿主消费的
  公共 API，工作区内零引用是常态，跳过死类判定。
- **协程混合模式识别**：文件显式声明协程回退（`Coroutine::isCoroutine()` / `inCoroutine()` /
  `Fiber::getCurrent()`）即视为已实现 CLI 回退（异步铁律允许），跳过 async_blocking，修复
  OIDC 短信/微信、oidc-client、mcp McpClient 的同步分支误报。
- **`@audit-ignore <code>` 豁免标注**：async_blocking / superglobal / dead_code / fqcn_dup 四规则支持
  文件内显式豁免（如队列消费者内同步 SMTP、CLI 超全局回退、radmin 真源同名副本），豁免即文档。
- **引擎自排查除**：AuditService.php 自身含探测模式字面量（brpop/curl_exec/TODO 正则），
  residue 与 async_blocking 自扫必误报，按文件名排除。
- **细节修复**：usleep/sleep 探测排除 `$var()` 调用（`$sleep()` 误报）；超全局同行多次命中按行去重；
  fqcn_dup 跳过带豁免标注的副本文件后判断。

## [v3.3.1] - 2026-09-01

### 代码清理与修复

- **DevCount 清理调试残留**：删除 `print_r(get_declared_classes())` 调试输出、注释掉的调试行与空 docblock，
  "Hello dev count" 文案改为中文、tab 缩进统一为 4 空格、等号两侧补空格，类头补中文注释
  （命令定位：统计/查看已加载类、函数与内存等开发信息，与 dev:status 定位互补，保留原命令）。
- **删除空壳死代码**：`src/Sync.php`（run() 空实现、未注册、无任何引用）整文件删除。
- **RocareerPlugin 残留清理**：defaultDescription 改为正确中文描述（同步 Radmin 插件目录到工作区）、
  删除空 `$dirs` 属性、`$configPath` 声明补空格、删除注释掉的调试输出行、
  `performDeletion()` 补 `: void` 返回类型，类头补中文注释。
- **Install 重写为 mcp 同款风格**：`$parent_dir` 改 camelCase（`$parentDir`）、英文模板注释改中文、
  install/uninstall/installByRelation/uninstallByRelation 补 `: void`、方法用途补中文注释。
- 无类注释的 5 个类（DevCount/DevCover/DevStatus/RocareerPlugin/Install）补中文类注释。

## v3.3.0 - 2026-08-28

### 新增 5 条工程质量审计规则（异步铁律 + 代码质量，2026-08-28 全量审计实战沉淀）

- **async_blocking 异步阻塞扫描**：常驻进程代码（`src/app`，排除 CLI command/Install/Fiber 封装）内
  BRPOP 长拉 / 同步 Guzzle HTTP / 同步 SMTP / curl_exec / usleep/sleep 阻塞事件循环全部报出
  （来源：channel 20s BRPOP 占死 worker、OIDC 短信/邮件同步发送、radmin get_ba_client 等实战案例）。
- **fqcn_dup 同名类冲突**：全工作区 namespace+class 对去重，同一 FQCN 多文件定义（含 PSR-4 加载不到的死副本）即报
  （来源：support\\StatusCode 三份同名定义实战）。
- **superglobal 超全局直读**：worker 内 `$_COOKIE/$_SERVER` 直读报出，提示走 `support\\Context` + `Request`
  （来源：oidc-client 设备透传失效实战；本次上线即捕获 radmin 3 处）。
- **dead_code 死类检测**：全工作区零引用（无 new/静态调用/::class/配置字符串）的非框架类报出
  （排除控制器/中间件/进程/验证器/上传驱动/support 反射类；来源：webman-migration 750 行死 Table 类实战）。
- **cross_copy 跨包文件重复**：不同包逐字相同的 .php 文件报出（排除多应用 lang 语言包）
  （来源：memory/knowledge cosine、ai emitUsage、crontab Fiber/Logger 复制实战）。
- 默认审计包列表补全 crontab/tiktoken/mcp/webman-status-code/webman-dev（此前遗漏）。
- 后台「工程质量审计」规则种子迁移 `20261028140000_radmin_webman_dev_audit_rules_v2`（幂等，需 migrate:run）。

## 未发布（Unreleased）

### 许可与版权

- 许可证由开源协议改为 proprietary（商业/内部专有），不适用任何开源许可证；LICENSE 文件同步替换为 Rocareer 专有许可文本。
- 版权声明统一为：Copyright (c) Rocareer Team. All rights reserved.；作者：albert@rocareer.com。

## v3.2.0 - 2026-08-27

### 新增「前端页面规范」审计规则（web_page，feat）

- 补齐前端盲区：此前六类规则全部面向 PHP 后端，AGENTS「模板优先、禁止从零手写」「只准用 baTable 约定」的前端硬性规范无机器审计；v3.2.0 新增 **web_page** 规则静态扫描各包 `web/src/views/backend` 的 Vue 页面，五项低误报检查：
  1. 禁止自创依赖注入 `inject('xxx')`（本 fork 仅 `baTable` 有 provide；`inject('config')` 曾致弹窗渲染崩溃）
  2. 禁止裸 `import axios from 'axios'`（必须 `/@/utils/axios` 的 createAxios 统一封装）
  3. 引用 baTable 体系组件（TableHeader/Table/PopupForm）必须初始化 baTable（禁止自建表格绕过 baTable；精确匹配导入，规避 `onTableHeaderAction` 子串误报）
  4. 编辑弹窗必须走 `baTable.onSubmit` 提交（禁止绕过 baTable 手写请求）
  5. 禁止 `/src/` 根路径导入（应使用 `/@/` 别名）
- radmin 包的 web 树是各包/业务工程页面的同步汇聚区（真源在各包 web/），该规则对 radmin 跳过，避免归属错乱。
- 实测（全量审计）：各包 web_page 全部 PASS（当前仓库无前端硬性违规，规则作为防回潮门禁）。
- 说明：弹窗表单字段与后端入参一致性暂为人工复核项（静态无法区分标准 CRUD 弹窗与特殊/透传弹窗，易误报，故不纳入自动化）。
- 迁移 `20261028130000_radmin_webman_dev_audit_web_page_rule`（幂等）为规则表补种子行，后台「审计规则」页自动出现，需 `migrate:run`。

## v3.1.1 - 2026-08-27

### 审计规则精简与修复（拒绝过度设计，fix）

- controller 规则：`?Response` 不再是问题（SSE 流式 / 可能 null 返回是合法写法）——只拒绝**完全无返回类型**的方法；
  删掉配套的 `return null;` 检查；
- permission 规则：① 按钮名支持驼峰并统一小写比对（radmin 按钮如 security/dataRecycleLog/index 此前漏扫误报）；
  ② 豁免 `$noNeedLogin` / `$noNeedPermission` 声明的方法（公开接口无需按钮节点——给 login/ajax 补按钮才是过度设计）；
- 实测：全量审计仅剩 agent 版本同步（并发在途，非规则问题）。

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
