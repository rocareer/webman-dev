## [v3.30.0] - 2026-09-27

### feat(ai): CRUD 设计链出回调档（`executeAsync()` 孪生 + 两档共用落账；同步档转回退路径）

- **动机**：宿主 Rolling 的去协程化收口（用户裁定「全改成 回调 + 事件触发 event」）走到**生态包 vendor 面**——
  `CrudDesignAgentService::callLlm()` 内的 `AgentGateway::chat()` 是该包**最后一处同步档 LLM 口**。
  同步档要求 fiber 协程上下文（`AgentGateway::assertCoroutineContext()`），在回调档消费链 / 纯净 CLI 里
  直接抛「上下文契约拒绝」；而包侧只能走同步口 ⇒ 该链在去协程宿主上要么被迫留协程、要么整链不可达。
- **做法（与宿主侧刀 4/刀 5 同款「按 LLM 边界分段 + 两档共用收尾」）**：
  - 新增 `executeAsync(string $requestId, callable $ok, callable $fail)`——消费者入口的回调档孪生；
  - 新增 `buildDesignAsync()`（自修复轮从 `while` 变续体续跑，**轮数上限/回灌文案/判据全部同源**）与
    `callLlmAsync()`（`chatAsync`，同 agentKey / 同 messages / 同 `max_tokens=16384` / 同 source+bizType）；
  - 落账抽 **两档共用唯一实现**：`settleDesign()`（成功/校验不通过三分支，逐字搬移）与
    `failDesign()`（失败 + happ 推送）；`execute()` 与 `executeAsync()` 只剩「等待方式」之差。
- **失败语义逐条对位（同步档 → 回调档）**：LLM 失败/输出非法 ⇒ 服务侧落 `status=failed` + 推送后
  **`$ok()`**（= 同步档 catch 后 `return`、消费类照常返回 = 队列 ack，**不进重试**）；`$fail` 只接
  **收口环节自身**的意外（落库/推送抛出）⇒ 交队列重试/死信；续体内意外就地改道 `$fail`
  （`asyncScope()` 只做队列标记携带、不套 `Async::guard`，回调里外抛会让延后收口槽悬挂到租约超时）。
- **时限归调用方**：包侧不引宿主 `app\support\Async`——回调档下等待语义转移到续体，超时由宿主消费类包
  （`CrudDesignConsumer::LLM_TIMEOUT = 600s`）。
- **活体读数**（宿主 Rolling，`dev:crud-design-request --inline`，裸 Select 零 Fiber 脚手架；队列侧
  同批 `--pause` 隔离以保归因）：3.2s 走通全链，草稿 `status=suggested`、`rounds=1`、设计 JSON 完整
  （表/字段/枚举/表单分组齐备）；库中该队列 waiting 恒 1、active 0（证明队列侧未参与）。
  该腿的价值即判据本身：CLI 主上下文（无 fiber）下若能走通，链上必无同步档调用——同步口会在
  `assertCoroutineContext()` 处响亮抛出。
- **未做（留档）**：
  - `src/command/templates/consumer/CrudDesignConsumer.php` 模板**未改**（仍是同步 `execute()`）——
    其他宿主未接 `rocareer/queue` 的 `AsyncConsumer`/延后收口，模板保持兼容；宿主自有的消费类副本
    （Rolling `app/queue/redis/CrudDesignConsumer.php`）由各宿主按同一形态自升。
  - 包侧同步档（`execute()`/`buildDesign()`/`callLlm()`）**保留为回退路径**，行内 `async-rule-exempt`
    留痕；随「vendor 同步口删除」批次一起撤（届时需先确认各宿主已升消费类）。
  - `embedding` 面**本包不涉及**（`AliyunDashScopeDriver` 的 `embedding()` 覆写面漂移在 rocareer/ai 仓，
    不在本包）。

### 补记

- **v3.29.3 / v3.29.4 两条 tag 无对应条目**（历史事实，只记不改）——本次升版按 tag 线 `v3.29.4 → v3.30.0` 走。

## [v3.29.2] - 2026-09-24

### 修复

- **接线配置：安装不再覆盖宿主分叉 + 卸载不再整目录删**（install-standard §三/§四.3 合规）：
  `uninstallByRelation()` 原为「整目录删」——宿主在 `config/plugin/rocareer/webman-dev/` 里的定制随包资产
  一起消失，紧随的 `install()` 见目录不存在又全量铺包默认 ⇒ **宿主策略静默丢失**（2026-09-24 queue 包
  实测事故：19 条队列塌进一个组、AgentGateway 契约闸拒绝 2454 条作业）。改法（与 rocareer/queue v1.8.7 同源）：
  ① 安装写清单 `runtime/rocareer-webman-dev-install-manifest.json`（相对路径 => 投放时 md5，**只登记与包内
  逐字节一致的文件**）；② 卸载**逐文件**按清单判 md5——宿主分叉与宿主自有文件一律保留并点名打印，
  只回收空目录，无清单则整目录按「宿主拥有」处理；③ 落盘口径改为「**目标已存在即只补缺失文件，不看
  `$isFirst`**」——宿主 composer 常把 post-package-update 接到 `support\Plugin::install`（恒传 true），
  旧口径每次 update 都全量覆盖，宿主定制被静默抹掉。
  安装侧补 else 分支：目标目录已存在时补齐包内新增文件（原为整目录跳过——标准明令禁止的旧语义）。
- **验证**：沙箱 20 包 × 两种入口（`installByRelation(true/false)`）共 40 组断言全过——宿主分叉逐字节
  存活、宿主自有文件保留、包内未改文件入清单并在卸载时精确删除、包内新增文件在更新时被补齐。

## [v3.29.1] - 2026-09-22

### fix(audit): 审计结果列表接 radmin 列表契约——列头排序（order）与高级检索（search）真生效

- **症状**：`audit.AuditResult::index` 手写实现（`DevAuditResult::orderBy('run_at','desc')->orderBy('id','desc')`），
  **不读 `order`** ⇒ 「代码审计 → 审计结果」页点表头排序无反应（同包的 `AuditProject`/`AuditRule` 早已接
  `queryBuilder()`，三页行为不一致）。
- **做法**：改 `DevAuditResult::query()` + `$this->applyListQueryContract($query, new DevAuditResult())`，
  默认 `run_at desc, id desc` **追加在契约之后**（用户排序优先、默认退居次要键，无 `order` 时行为不变）。
- **实测**（宿主 Rolling，`?order=id,asc|desc`）：1,2,3,4 升序 / 53,52,51,50 降序（旧版两种都返回同一顺序）。

## [v3.29.0] - 2026-09-22

### feat(audit): 新增 frontend_raw_fetch 规则——前端禁裸 fetch/XHR（业务码信封唯一出口）

- **由来**：20260922 权限拒绝语义评估拍板「内部 API 保持 HTTP 200 + 业务码信封，禁绕信封」；
  wh 走查 A3「导出假成功」实证裸 fetch 只看 res.ok 会把 HTTP 200+code 401 的错误 JSON 当
  CSV 下载（绿 toast 打开全是 error_trace）。
- **规则**：各包 web/src 与全域盲区（dev 宿主 web 树 + super/web/src，radmin 条目承载 sweep）
  内 fetch( / new XMLHttpRequest 即报；剥离三类注释防自指假命中；utils/download.ts 与
  utils/happClient.ts 豁免且双向断言（豁免文件无裸调用即报收敛）；radmin/web/src 同路径
  「全家桶继承页」跳过（上游存量不由宿主修）；skyline 小程序无 fetch API 不扫。
- **配套**：print-erp 主树 12 文件导出/打印全部迁移 utils/download.ts 单一真源
  （downloadByToken/openByToken/runDownload），首扫 205 命中 → 主树清零
  （余 193 全为 drill/sim/w1-w13 worktree 镜像，随同步波收敛）；规则清单
  docs/audit-rules.md 重生成（23 条）。

## [v3.28.2] - 2026-09-22

### fix(audit): radmin_dev_audit_result 补 update_time 列（建表迁移漏列，落库即 42703）

- 建表迁移（20260827090211）给 `radmin_dev_audit_project`/`radmin_dev_audit_rule` 都加了
  `update_time`，唯独漏了 `radmin_dev_audit_result`——radmin BaseModel 自动时间戳在 save()
  时写该列，审计「运行」一落库即 `SQLSTATE[42703] Undefined column`（同步/异步同炸；
  此前未被发现的 traced 原因：结果落库只在页面 run 路径，CLI 审计不落库）。
- 修复：新迁移 `20260922084602_radmin_dev_audit_result_add_update_time`（幂等：先查
  information_schema 再 ALTER；Rolling 异步审计作业化冒烟时暴露）。

## [v3.28.1] - 2026-09-22

### fix(install): 接线落盘改「存在即跳过」——composer update 会以 install(isFirst=true) 重拷覆盖宿主

- 实证：Rolling 宿主 `composer update rocareer/webman-dev`（v3.28.0）时 webman composer 安装器回调
  `support\Plugin::install`（**isFirst=true**，不是 update()），`installConsumers` 的
  `!$isFirst && is_file` 守卫被绕过——宿主带 QueueConsume 生命周期记账的
  `app/queue/redis/CrudDesignConsumer.php` 被裸模板覆盖；`installByRelation` 的
  `$isFirst ||` 同病，宿主 `config/plugin/rocareer/webman-dev/` 被整目录重拷。
- 修复：两处落盘改为**目标已存在一律跳过、缺失才补**（与文档口径「缺失才写」对齐）；
  $isFirst 参数保留签名兼容但不再参与判定。

## [v3.28.0] - 2026-09-22

### feat(audit): 「运行审计」可作业化（async=1），执行体抽取 runProjects 共享

- **`AuditProject::run` 新增 `async=1`**：校验后经 rocareer/queue 作业层（`Job::submit`，宿主
  `audit-run` 队列）立即返回 `job_id`，执行与收口在队列消费，终态随 `job.settled` 帧定向推给
  提交者（受众=作业层提交时解析器）；作业层未安装时**显式报错不静默降级**。缺省（无 async）
  同步路径行为与历史逐字节一致，存量宿主零影响。
- **执行体抽取 `AuditService::runProjects(array $projectIds = [])`**：取项目与启用规则 →
  `audit()` 全量 → `dev_audit_result` 逐规则落库 + 项目快照回写 → 返回轮次汇总；同步控制器与
  宿主消费类共用，消除双份落库/汇总逻辑。执行可达分钟级——子进程执行自带 fiber 协程回退，
  可安全挂在 fiber 消费进程。
- **宿主接入清单**（Rolling 同批落地）：消费类 `app/queue/redis/AuditRunConsumer.php`
  （queue=audit-run，batch 组，QueueConsume 记账）+ 队列元数据（plugin=app / biz_kind=audit_run /
  biz_field=ids）+ 前端 `waitJob` 零轮询收尾。

## [v3.27.0] - 2026-09-21

### feat(audit): 审计扩展协议——基础设施出引擎，业务端出规则（config/audit.php）

- **分工口径落地**：`AuditService` 只做执行服务（扫描/汇总/退出码/CLI+MCP+后台三入口）；审计规则与布局
  知识由**业务端**在工作区根 `config/audit.php`（全域）与包目录 `config/audit.php`（只作用于该包）声明。
  协议键：`event_registry_globs`（事件监听登记文件 glob）/ `button_name_globs`（权限节点字面量额外扫描源）/
  `rules`（自定义规则类，实现新契约 `app\admin\service\AuditRuleContract`）/ `skip`（逐单元逐规则豁免，
  必须写理由，报告按「业务端豁免」留痕）。glob 相对**声明文件所在目录**解析；globs/rules 并集、skip 包级优先。
- **动因（两条 Rolling 布局误报实证）**：① `event_standard` 的监听注册表 glob `$root/*/config/event.php`
  匹配不到 rolling 布局的 `plugin/<名>/config/event.php`（实测两条 glob 零命中）→ 全仓插件事件被误判孤儿事件；
  ② `permission` 规则只扫迁移——业务端把按钮名集中在种子/常量清单时无从声明。二者现均由业务端在
  `config/audit.php` 自行声明，引擎照单代跑；未声明的 family 工作区行为与历史逐字节一致。
- **`--list-rules` 规则描述同步**：permission / event_standard 两条补扩展协议说明（清单由引擎常量自动生成，
  重跑 `--write-doc` 即同步）。
- 本版同时携带上一提交 `1c07bc0`（监听器审计报错文案的全角括号插值修复，此前已推未发条目）。

## [3.26.4] - 2026-09-21

### fix(chore): 补登记三处软依赖（mcp / agent / happ-client）

- `config/plugin/rocareer/webman-dev/event.php` 注册 `\app\mcp\collection\CrudCollection`；
  `CrudDesignPusher`/`CrudDesignAgentService` 以守卫引用 happ-client / agent —— 均为软依赖，补 suggest。

## [v3.26.3] - 2026-09-21

### fix(audit): rolling 布局下命令/任务类不算死代码（框架自动发现/按 DB 任务表实例化）

`--root=<Rolling>` 实测：`dead classes` 把 `app/command/DevMakeCrud.php` 等**框架自动发现**的命令类
报成死代码（webman console 扫描注册、无显式引用；`app/task/*` 亦由 `rocareer/crontab` 按 DB 任务表实例化）——
rolling 布局下跳过 `(app/)?(command|task)/` 路径下的类定义（仅 rolling 生效，family 行为与历史一致）。

## [v3.26.2] - 2026-09-21

### fix(audit): `common_utils` 规则加 composer.json 缺失守卫——Rolling 插件单元（无 composer.json）不再崩审计

v3.26.1 实测 `--root=<Rolling>` 时崩在 `common_utils`：规则无守卫地读 `<单元>/composer.json`
（`file_get_contents(...): Failed to open stream`）——rolling 的插件单元没有 composer.json，
审计整个中断。修法：缺文件即 `return null`（无法判定 radmin 依赖 → 跳过而非误报/崩溃），
与 `checkVersion` 的 `!$hosts` 守卫同款语义。已复核同文件内无其它对 `$dir/*` 的无守卫读取。

## [v3.26.1] - 2026-09-21

### fix(audit): Rolling 布局补「单元列表」——`app` + 各 `plugin/<名>`（v3.26.0 适配器的收口件）

v3.26.0 让 `--root=<Rolling>` 能进规则，但缺省单元列表仍是家族包名（radmin/ai/memory/…），
实测吃 29 条 coverage FAIL（「包目录不存在」）——**ROOT 认了、单元对不上**。

- `AuditService::defaultUnits($root)`：family → `DEFAULT_PACKAGES`（历史行为不变）；
  rolling → `app`（主应用，单元根 = 工作区根）+ 各 `plugin/<名>`（按名排序）。
- `AuditService::pkgDir()`：rolling 下 `app` → 工作区根、其余 → `plugin/<名>`。
- 接线三处：`rocareer:audit` 命令、MCP `quality_audit` 工具、后台审计页共用同一取数（走 service，不再各自读常量）。
- 回归：family 布局（`--root=<vendor/rocareer> --pkg=radmin|webman-dev`）输出与 v3.26.0 一致；
  rolling 布局实测无 phantom 单元，规则进入真实扫描。

## [v3.26.0] - 2026-09-21

### feat(audit): 审计支持 Rolling 布局（`app/` + `plugin/<名>/app/**` 工作区）——22 条规则不再只认家族包 `src/`

此前 `rocareer:audit` 只认家族包布局（`<pkg>/src/app/**`），对 Rolling 这类工作区直接报
`workspace root not found`（实测 `--root=<Rolling>`）——即 Rolling 自身的 `app/` 与 9 个插件的代码
**完全在审查射程之外**，其「门禁」只剩语法/图标/前端 typecheck 三条。

- **布局判定**：`resolveCandidate()` 增加 Rolling 工作区识别（`plugin/` + `app/` + `start.php|webman` 三件套）→
  返回工作区根；`audit()` 每轮判定 `layout`（`family` 缺省 / `rolling`）并记录 `rollingRoot`。
- **路径统一出口 `srcPath()`**：规则内 12 处硬编码 `"$dir/src/..."` 收束为 `$this->srcPath($dir, 'app/admin/controller')` 等——
  family 仍是 `<unit>/src/<rel>`（**产物与历史逐字节一致**，已用 radmin/webman-dev 两包前后输出对比验证）；
  rolling 映射为 `<unit>/<rel>`（单元根 = 含 `app/` 的那层：主单元 = 工作区根、插件单元 = `plugin/<名>`，
  故 `app/admin/controller` 等相对段两边同形）。
- **迁移口径 `migrationsDir()`**：rolling 是**单库单目录**（工作区根 `database/migrations`，插件迁移同落此处并在文件头注明归属）；
  `workspaceMigrations()` 增补工作区根迁移的扫描（撞号/畸形/静默忽略三类判据照旧，跨插件撞号同样拦）；
  `checkMigration` 的归属前缀与 `migrationBelongsToPackage()` 在 rolling 下统一为 `database/migrations/`（配合
  `reportedMigrationStamps` 去重，多单元不重复报）；`permission` 规则的按钮来源目录同走 `migrationsDir()`。
- **自然跳过项**（不改行为）：`version`（依赖 `../dev/*/composer.json` 钉版，rolling 无此概念 → 既有 `!$hosts` 守卫直接 not-applicable）、
  `install_standard`（rolling 插件无 `src/Install.php` → 既有守卫跳过）。
- **已知限制（如实）**：`common_utils` 按 `<unit>/composer.json` 判「是否依赖 radmin」——rolling 插件单元无 composer.json，
  会被当纯 SDK 跳过（不误报，但也不覆盖）；`cross_copy` 在 rolling 下按工作区扫，覆盖面与 family 口径一致。
- **实测**：`rocareer:audit --root=<Rolling> --pkg=ops` 等可正常扫描 Rolling 单元（此前直接 root not found）；
  家族布局回归：`--root=<vendor/rocareer> --pkg=radmin|webman-dev` 输出与 v3.25.1 逐字节相同。

## [v3.25.1] - 2026-09-21

### fix(crud-design): AI 草稿提示词升 v3——落位 target 块从「被模型丢掉」到「需求有就必须原样体现」

Rolling 端到端实测 AI 草稿链路（dev:crud-design-request → 队列消费者 → LLM → 草稿台账）时发现：
`systemPrompt()` 仍写「设计态契约 v2」，只描述 table/fields，**未提 target 块**——即使请求里明确给了 target，
模型也整块丢掉，草稿 confirm 后落回宿主 `app/` 默认落位（v3 落位能力在 AI 链路上等于不可用）。

- 提示词升 v3：`version` 示例改 3；新增第 8 条 target 落位块契约（profile/plugin/controller_dir/model_scope/menu），
  并写明「需求里出现插件/落位/菜单目录或父级/图标时必须原样体现；没提就整块省略」「icon 用请求给的名字（FA4 校验）、
  parent/parent_title 不许编造」。
- 实测（Rolling，agent=memory-coder / deepseek-flash）：修复前草稿无 target（模型视其为多余键）；
  修复后草稿按请求产出 `target.profile=rolling-plugin + plugin/controller_dir/menu`，confirm 落位正确。
- 边界：本版只修提示词，未给 `rocareer:crud-design confirm` 加「人工补 target」的选项——草稿没带 target 时
  仍走宿主默认落位（人工可在 confirm 前用 `show` 看设计、必要时改草稿）。

## [v3.25.0] - 2026-09-21

### feat(crud): 设计 JSON v3 落位目标（target profile）——模块可落 plugin/<名>/app/，管线补迁移先行/门禁/新产物回执

配合 radmin v5.10.x 的 target profile（`app\admin\library\crud\Target`），把「生成到宿主 app/」扩展为
「按目标落位」并把生成闭环补全（Rolling 端到端实测通过：生成 → 建表 → 菜单种子 → 三门禁全绿 → HTTP 垂直打通）。

- **设计 JSON v3**：顶层新增 `target` 块（`profile` / `plugin` / `controller_dir` / `model_scope` / `header` /
  `ddl` / `menu_migration` / `menu{enabled,parent,parent_title,icon,weigh,title}` / `route_file` / `backend_lang`）。
  `sanitize()` 白名单净化（未知键丢弃并告警）；`validate()` 委托 radmin Target 的严格规则（单一实现），
  宿主 radmin < v5.10.0 时给结构化升级提示。**无 target 时行为与 v2 逐字节一致**（`test:crud-designer` 的 v1 黄金哈希未变）。
- **落位与产物**：插件形态落 `plugin/<p>/app/{admin/controller/<dir>,model,validate,admin/lang/zh-cn}`，
  多段表名折叠「域/末段」（`evaluation_run_funnel` → `evaluation/Funnel.php` + `EvaluationRunFunnel` 模型 + `evaluation/funnel` 页面）；
  `targetFiles()` 目标感知（含路由文件与后端语言包），冲突预检覆盖新产物，**幂等 merge 的路由文件不计冲突**（否则「往已有插件加模块」主场景必失败）。
- **管线**：`target.ddl=migration-first`（插件形态缺省）时先写迁移并 `migrate:run` 建表再出码（迁移是 DDL 唯一真源）；
  出码后若写了菜单种子迁移则再补跑一次 `migrate:run` 应用它——生成结束即「菜单可用」，不留 pending 迁移等人记得跑。
- **CLI**：`rocareer:make-crud` 新增 `--check`（只预检：落位/冲突/**图标实存性**/引擎可用性/表结构现状，不落盘）、
  `--menu=migration|now|skip`、`--no-gates`；`--demo` 同时打印 v3 与 v1 示例；回执新增
  `target/ddl/route_file/menu_migration(_reused)/backend_langs/written_files/checks/gates/migrate_menu`；
  **退出码** 0 成功（含门禁全绿）｜1 失败｜2 待人工裁决（冲突清单/门禁红/预检不合格）。
- **门禁串联**（宿主存在即跑，缺则如实跳过）：`bin/lint.php`（或逐个 `php -l`）→ `scripts/check-menu-icons.mjs`
  → `npm --prefix web run typecheck`；`--audit` 的审计未过同样计入退出码 2。
- **图标预检**直击历史事故（助手菜单写 FA5 `fa-robot`、宿主打包 FA4 → 侧栏空框）：`fa fa-X` 查宿主
  `font-awesome.css` 的 `.fa-X:before`；`el-icon-X` 查 `@element-plus/icons-vue`；`local-X` 查 `web/src/assets/icons/X.svg`。
- **fix(web)**：审计三页在严格 tsconfig（宿主 vue-tsc 门禁）下的类型错误——`Promise.all` + 解构需显式传
  `createAxios<anyObj>(...)`（否则退化为 unknown）、`baTable.onTableHeaderAction` 需第二参（`{event, ids}`）。
  此前包内 web 树未过宿主级 typecheck，Rolling 移植时被门禁抓出。
- **测试**：`test:crud-designer` 新增 `[2v3]` 段（折叠/落位/回执/非法值/未知键共 18 项断言），全套 61 项断言通过；
  radmin 侧配套 `CrudTargetTest`（14 用例，含「生成文件能否被执行」的结构断言）。

## [v3.24.0] - 2026-09-17

### feat(audit): 新增 vue_theme_hardcode 规则——Vue 样式段 EP 调色板硬编码门禁（print-erp 前端全域样式审计实证）

- `AuditService::RULES` 新增第 22 条规则 `vue_theme_hardcode`：`.vue` 的 `<style>`/`<template>` 段写死
  Element Plus 官方默认调色板（`#409eff/#67c23a/#e6a23c/#f56c6c/#909399/#ecf5ff/#d9ecff`）即报——
  主题语义色被固化，用户切换主题色/暗色模式后与全站脱节（print-erp flow 节点状态色实证，
  工作区 TASK-20260917-013/019 前端全域样式审计）。
- 合法形态 = `var(--el-color-*, 色值)` 带 fallback 双写（扫描前剔除再匹配）；`<script>` 段不扫
  （ECharts/SVG 画布色板属运行时配置，主题跟随可选 getComputedStyle 快照）。
- 扫描范围 = dev 宿主工程 web 树（radmin 条目承载 `sweepVueThemeHardcodeBlind`，与 icon_attr 盲区
  先例同构，每轮一次实例缓存）；相对路径在 `radmin/web/src` 存在同路径文件的「全家桶继承页」跳过
  （真源在 radmin，上游存量不由宿主修）；src 各包 web 树存量（agent/ai/happ/mcp/psyvoyage 共 9 文件）
  待各包自行收口后开启；文件标注 `@audit-ignore vue_theme_hardcode` 显式豁免。
- 实测：print-erp 修复后 PASS（222 业务页扫描 / 914 继承页豁免计数正确）；反证抓到 print-erp-drill
  演练树 2 处真阳性（同步修复后全绿）。配套种子迁移
  `20260917033425_radmin_webman_dev_audit_vue_theme_hardcode_rule.php`（幂等按 name 去重）。
- 纪律全文沉淀 buildadmin-web 技能「按钮与主题纪律」章节（含 el-button 必带 v-blur 等四条）。

## [v3.23.3] - 2026-09-16

### fix(budget): CRUD 设计 agent 输出预算 8192 → 16384（老板定版「8192×2」）

- `CrudDesignAgentService::callLlm` 显式预算 8192 → **16384**（生成的建表/字段 JSON 被截断时，
  下面那句「content 空串，建议放大预算排查渠道」正是本类历史症状）。
- 宿主保底同步改为 16384 见 `rocareer/agent` v2.15.3。

## [v3.23.2] - 2026-09-16

### fix(migration): 迁移文件版本号回归真实时间戳（根治占位未来日期，TASK-20260916-544）

本包 10 个迁移文件原为手编占位未来日期版本号，违反「migrate:create 真实时间戳」
铁律并破坏 Phinx 版本时序（全新空库上晚于真实日期业务种子造成断链，TASK-20260914-355）。
全部按 git 首提交时间戳重命名（同秒撞号按秒进位顺延），**文件内容零改动**；已执行库的
ra_migrations 记录以 UPDATE 同步改号（零重执行）。

## [v3.23.1] - 2026-09-15

### chore(audit): 默认包清单 experiment → psyvoyage（包改名跟随）

PsyVoyage 改名（原 rocareer/experiment，目录 src/experiment → src/psyvoyage），
audit 默认包清单同步；`--pkg=psyvoyage` 生效，旧键移除。
## [v3.23.0] - 2026-09-15

### feat(audit): 新增 icon_attr 规则——el 组件 icon 属性禁传 CSS 类名

- **规则**：`.vue` 内 `icon="fa*"` / `:icon="fa*"` / `:icon="'fa*'"` 属性传 font-awesome 类名
  即报错——Element Plus 按组件渲染 icon 属性，传类名字符串会 `createElement('fa fa-...')`
  抛 InvalidCharacterError，页面白屏且此后所有菜单点击空白（dataio 导入向导 v1.0.5 与
  print-erp 双实证）。
- **正解**：`<Icon name="fa fa-*" />` 子节点（Icon 经 `common.ts` 全局注册）或已注册组件名；
  script 段给菜单 icon 字段赋类名（`items.icon = 'fa fa-circle-o'`）为合法场景，lookbehind
  排除 `.`/`-` 前缀（属性访问与 `data-icon`），防误报（colleague/print-erp 实测四误报已消除）。
- **盲区 sweep**（radmin 条目承载，与 happ_frontend 同款）：dev 宿主工程 web 树、
  super/web/src、skyline 每轮一次；实测 719 盲区 .vue 零命中 + 阳性金丝雀精确咬人。
- 文件标注 `@audit-ignore icon_attr` 显式豁免；种子迁移 `20260915035817`（幂等 + hasTable 守卫）。

## [v3.22.0] - 2026-09-12

### feat(audit): 规则自检清单自动生成 + MCP list_rules（FACTORY P3）

- `rocareer:audit` 新增三形态：`--list-rules`（打印规则目录）、`--list-rules --json`
  （结构化输出供脚本/AI 消费）、`--list-rules --write-doc=路径`（自动生成 Markdown 自检清单）。
- **单一真源 = `AuditService::RULES`**：清单由引擎常量直接渲染，规则增改后重跑命令即同步，
  不存在「文档写了、引擎没做」或反过来的漂移（实证：临时改规则标题后重生成，文档同步命中）。
- 落地 `docs/audit-rules.md`（工作区级，自动生成物，顶部标注勿手工编辑）：规则索引表 +
  逐条判定与修复 + 豁免机制（`@audit-ignore <code>`）+ 三种使用方式。
- MCP `quality_audit` 新增 `list_rules=true`：不执行审计，直接返回 20 条规则目录
  （供 AI 写码前自查，与 CLI 同源同口径）。
- 用途：补齐「AI/人写码前的自检清单」这一协作缺口——此前 20 条高质量规则只存在于引擎常量里，
  写码者须逐份翻 AGENTS.md 与各 SKILL.md 才能拼出检查项。

## [v3.21.1] - 2026-09-12

### fix(test): test:crud-designer --pipeline 自清理收口（真实零残留）

浏览器验收 P2 时发现该自检会残留：菜单节点未删（Menu 类命名空间写成 `app\admin\library\Menu`，
真源是 `app\common\library\Menu`——写错静默 no-op）、前端 views/lang 空目录未清理、crud_log 行只标
`status=delete` 反复跑会累积。现改为：真源目录删菜单（含子孙）、递归清理代码/页面/语言各层空目录、
物理删除 crud_log 行。跑完 `test:crud-designer --pipeline` 后菜单/表/文件/crud_log 全部归零。

## [v3.21.0] - 2026-09-12

### feat(design): AI 模块设计生成管线 + 生成后闭环编排 + MCP 开发工具链（FACTORY P1）

- **AI 设计生成**（`app\admin\service\CrudDesignAgentService` + 草稿表 `radmin_crud_design_draft`）：
  `suggest()` 落 pending 草稿 + 投递队列 `crud-design`（消费者模板 `CrudDesignConsumer` 由 Install 落盘
  宿主 `app/queue/redis/`）→ `execute()` 调 `AgentGateway::chat` 产设计 JSON → `stripJsonFence` →
  `CrudDesignService::sanitize`（反幻觉白名单）→ `::validate`（结构化错误）→ 不通过**回灌错误自修复一轮**
  → 落草稿 `status=suggested` + happ 推送（`crud.design.suggested`）。
- **红线（同 dataio）**：`suggest()` 只落草稿，代码无任何直达 generate 的路径；出码仅在 `confirm()` 内；
  **MCP 不提供确认工具**（防 AI 绕过人工）；人工入口 = CLI `rocareer:crud-design confirm <id>`
  （新增命令，含 `list/show/confirm/reject`，P2 将补后台可视化设计台）。
- **共享出码执行器** `CrudDesignGenerator`：把「净化→校验→写迁移→冲突预检→调 CrudService」收敛为单点，
  CLI 与 `confirm()` 共用，两条路径产物一致。
- **生成后闭环**：`rocareer:make-crud` 新增 `--migrate`（生成后自动 `migrate:run` 建表）、
  `--audit [--audit-pkg=包名]`（生成后自动审计）；`--json` 模式进度行改走 stderr，stdout 只出 JSON 回执。
- **MCP 工具链**（`/mcp/crud`，集合 key=`crud`）：`crud_generate` 升级结构化回执（设计版本/字段名单/
  目标文件/迁移/crud_log_id）并支持 v2 设计对象与 `run_migrate`；新增 `crud_design_suggest`（AI 草稿，
  异步；risk=write）、`crud_design_export`（反导出设计 JSON v2；safe）、`module_rollback`（回收模块：
  删代码+菜单+空目录，可选删迁移/drop 表；drop_table 需 `confirm=true`；write）、`migrate_status`（safe）。
- **修复**：`rocareer:audit` 遇到包目录不存在的 coverage 失败时，把合成 issue 数组当字符串拼接 →
  `Array to string conversion` 崩溃；CLI（`Audit.php`）与 MCP（`AuditCollection`）双路径归一化渲染，
  现在输出可读的「包目录不存在」提示而非崩溃。
- 自检：`test:crud-designer` 新增 `--pipeline`（草稿→确认→出码→导出 链路，写临时模块后回收），
  全量 51 项断言。

## [v3.20.2] - 2026-09-12

### fix: 死类检测提前终止扫描修复（use 导入分支 break 2 → break）

`rootClassRefs` 的 use 导入短名引用分支误用 `break 2`：任何其他类先在扫描序列早期的文件命中导入匹配，就会连带终止外层**全工作区文件扫描**，后续文件的引用永远扫不到 → 大批类被误报「全工作区零引用」死代码候选（experiment AnalysisReportService 实证：引用就在 Data.php，却因字母序更早的包先触发 break 而恒 false）。改为只 `break` 本层导入循环，外层文件扫描继续直至扫完。影响面：所有依赖该门禁的发版判定（误报致假红），无误杀（只多扫不漏扫）。

## [v3.20.1] - 2026-09-12

### fix: audit 规则种子迁移补「全新库全量重放」守卫（colleague 新库首跑发现）

audit 规则种子迁移（20260909~20261231 十条）版本号早于建表迁移 `20261028120000_radmin_webman_dev_audit_page`（历史未来时间戳风格），全新库按版本号排序先跑种子而 `ra_radmin_dev_audit_rule` 表尚未建 → 直接炸。本次给全部无守卫的种子迁移统一补 `hasTable` 守卫（表未建跳过，规则行由建表迁移种子收口；存量库 phinx 已记录不重跑，零影响）。

## [v3.20.0] - 2026-09-12

### feat(design): 设计态契约 v2 + CrudDesignService 净化/校验/反导出（FACTORY P0）

- **设计态契约 v2**（`CrudDesigner`，新增键全部可选，**v1 输入输出逐字节不变**）：
  字段级 `options`（结构化选项，自动转 comment 字典）/ `remote`（关联表字段，向引擎
  下发 remoteSelect 契约并自动推导 controller）/ `form`、`table`（字段级属性，引擎
  getFormField/getTableColumn 消费）/ `group`；表级 `default_sort` / `is_common_model` /
  `form_layout`（表单分组布局，决定表单项顺序）。`design_type` 白名单新增
  `remote_select`（bigint）/ `remote_selects`（varchar 1500）。
- **新增 `CrudDesignService`**（`src/support/CrudDesignService.php`）：
  `sanitize()` 反幻觉白名单净化（未知键丢弃、非法属性剔除、remote 缺 table 降级，绝不放行）；
  `validate()` 返回**结构化错误数组**（field/code/message，规则与 parse 同一套但收集全部错误，
  供 AI 一轮自修复）；`export()` 从 `ra_admin_crud_log` 反向导出设计 JSON v2（含 options/remote 还原），
  供 AI 参考同包先例与可视化再编辑。
- **CLI `rocareer:make-crud`**：新增 `--json`（结构化回执：设计版本/字段名单/目标文件/迁移/日志 id）、
  `--dry-run`（只净化校验预览不写盘）、校验失败回执带结构化 error_code + errors（不再只吐单条文案）。
- **新增 `test:crud-designer` 自检命令**：v1 契约黄金哈希回归（[1k] 冻结基线，v2 改动不得移动 v1 输出）
  + v2 增强（options/remote/layout/form/table）+ sanitize 净化 + validate 结构化错误 + export
  round-trip，共 43–45 项断言。
- **修复**：`config/plugin/rocareer/webman-dev/command.php` 补齐 `MakeCrud` 注册（历史遗漏，
  新装宿主缺 make-crud 命令）；`src/config/plugin/...` 遗留副本同步补齐。
- 方案：`docs/radmin-dev-factory-plan.md`（FACTORY P0；P1 AI 生成管线 + 闭环编排、
  P2 可视化设计台、P3 协作深化另行建卡）。

## [v3.19.1] - 2026-09-11

### feat(audit): DEFAULT_PACKAGES 注册 dataio——audit 全量门禁覆盖新导入导出包

- `AuditService::DEFAULT_PACKAGES` 追加 `'dataio'`（2026-09-11 rocareer/dataio v1.0.0 首发后补登记）；
- 此前过渡期用 `rocareer:audit --pkg=dataio` 单包校验（已全绿），本版起全量 audit 自动覆盖；
- pending-decisions P9 随本版收口。

## [v3.19.0] - 2026-09-11

### feat(audit): 新增 comsearch_contract 规则——admin 控制器禁手写解析 search 数组

- 高级检索/排序定为 radmin 基础能力（Backend::applyListQueryContract 一行接入，
  v5.5.0）：admin 控制器直接 ->input('search') 解析即 FAIL（已接契约仍残留解析段
  同报，防双轨双写）；@audit-ignore comsearch_contract 豁免正当映射场景。
- 金丝雀实测咬人（临时控制器 file:line 精确定位 + 修复指引），全域 22 包审计
  零误伤；种子迁移幂等播种 radmin_dev_audit_rule。

## [v3.18.3] - 2026-09-11

### 审计注册表纳入 rocareer/notify

- DEFAULT_PACKAGES 新增 notify（通知基础设施包，2026-09-11 建）：php -l / 控制器风格 / 权限节点 /
  迁移命名 / 版本同步 / 前端页面 / happ 前端 / 异步阻塞 / DTO / LLM 网关 / ORM / 事件 / 公共工具 /
  Install 标准全规则纳管，消除新包审计盲区。

## [v3.18.2] - 2026-09-10

### 审计覆盖状态与缺失包假绿收口

- 审计结果新增 status，未适用规则标记 not_applicable；缺失包/扫描根生成 coverage fail。
- CLI/MCP 审计输出区分未适用与失败，并展示审计覆盖规则。

## [v3.18.1] - 2026-09-10

### 增强：happ_frontend 纳入全域前端盲区扫描（dev 宿主工程 / super / skyline）

背景：老板复查「检查全域 happ 应用，确保全部都是标准的，包括前端」——包级规则只扫
src/*/web/src，dev 宿主工程（cc-knowledge 等）、super/web/src、skyline 小程序不在
任何包内，属门禁盲区（migration 规则纳入 dev 各工程迁移目录同款先例）。

- **radmin 条目承载盲区扫描**：checkHappFrontend 对 radmin 不再跳过，改执行
  sweepHappFrontendBlind（每轮审计一次，audit() 内实例缓存重置）——glob dev/*/web/src、
  super/web/src、skyline（排除 node_modules/dist/public/unpackage/miniprogram_npm/vendor），
  口径与包级一致（new WebSocket(、wx.connectSocket、裸 ws://、wss:// 字面量即报错）；
- **首轮全域盲区实扫**：59 个盲区前端文件零违例（cc gen/pointgen 的 fallbackPollTimer
  为铁律豁免的 WS 断线兜底、exam 草稿自动保存为本地计时、experiment 全事件驱动+门控兜底）；
- **阳性金丝雀实测**：向 dev 工程注入手写 WS 文件即 FAIL（2 issues：手写连接+硬编码地址），
  删除即恢复 PASS——门禁真实咬人；
- **规则种子描述同步迁移**（20260910112312）：radmin_dev_audit_rule 的 happ_frontend 行
  description 更新为同款口径（≤500 字，幂等，新库 hasTable 守卫）。

## [v3.18.0] - 2026-09-10

### 增强：audit 新规则 happ_frontend（全域 happ 前端接入门禁）

背景：老板拍板「全域统一 happ 规范，前端不要自己乱写」——浏览器实时接入只认
rocareer/happ-client SDK（v0.3.5 起双版本定型：JS 版 useHapp / Vue 版 useHappConnection）。
前端手写 WebSocket 绕过 HMAC 凭证认证、服务端心跳与指数退避重连，硬编码 ws:// 地址
与服务端凭证接口下发的 endpoint 冲突，均属规范违例。

- **新增 RULES 规则 happ_frontend「happ 前端接入规范」**：静态扫描各包 web/src（vue/ts/js），
  命中 `new WebSocket(` 或裸 ws://、wss:// 地址字面量即 FAIL（每文件一条明细防刷屏）；
- **豁免口径**：SDK 真源文件（utils/happClient.ts、composables/useHappConnection.ts）、
  文件标注 `@audit-ignore happ_frontend`；radmin 包 web 树为同步汇聚区（真源在各包 web/）跳过；
- **规则种子迁移**（20260910105906，migrate:create 精确到秒）：radmin_dev_audit_rule 幂等
  按 name 去重插入规则行（weigh 79，enabled），dev/full migrate:run 已应用、DB 行已验证；
- **CLI ruleLabel** 同步补 happ_frontend 显示名「happ frontend sdk」；
- 首轮全量基线绿：现有各包前端（chat/agent/ai/knowledge/memory/crontab/mcp/experiment/happ 等）
  全部经 SDK 接入，零手写 WS，规则上线零存量违例。

## [v3.17.2] - 2026-09-10

### 修复：审计引擎静态缓存跨轮次脏读（常驻进程假 PASS/FAIL）+ CLI 展示补齐

- **静态缓存脏读（主修）**：`AuditService::$rootScanCache`（rootClasses/rootClassRefs/rootFileHashes/cross_copy/事件注册表共用）跨审计轮次永不清空——CLI 一次性进程无碍，但 MCP worker / 后台「工程质量审计」管理页是常驻进程，改代码后再跑 `quality_audit` 仍读上一轮文件快照 → 假 PASS/假 FAIL。修复：每轮 `audit()` 开始清空（轮内跨规则复用缓存不受影响）。
- CLI `ruleLabel` 补 6 条新规则展示名（dto_contract/llm_gate/orm_migrated/event_standard/common_utils/install_standard），此前回退显示原始 code。
- `checkResidue` note 修正：原恒为 `none (TODO/FIXME count: 0)`，现输出真实计数。

## [v3.17.1] - 2026-09-09

### 修复：update_audit_migration_rule 全新库排序炸裂（阻断 fresh DB migrate:run）

- 现象：该迁移版本号 20260909131325 小于建表迁移 radmin_webman_dev_audit_page（20261028 未来时间戳风格存量），全新库按版本号升序会先跑规则更新 → `relation "ra_radmin_dev_audit_rule" does not exist`，migrate:run 整体失败（super 建仓首迁踩中）。
- 修复：up() 增加 hasTable 守卫，表未建直接跳过（规则行由建表迁移的种子逻辑负责）；已执行存量库 phinx log 已记录不会重跑，零影响。
- 教训：依赖「未来时间戳风格存量表」的迁移必须自带 hasTable 守卫（新库排序 = 版本号升序，未来号 = 最后跑）。

## [v3.17.0] - 2026-09-09

### 增强：audit migration 规则升级「迁移命名与查重（精确到秒）」，与 webman-migration v2.4.0 运行时强检同口径

背景：老板拍板迁移文件命名铁律——版本号务必精确到秒，禁止「年月日+000000」。
webman-migration v2.4.0 运行时已扩展强检，本包 audit 门禁同步收口（原 workspaceMigrations
对非 14 位文件直接 continue 跳过，是盲区）。

- **checkMigration / workspaceMigrations 扩展**：在原有撞号查重之上新增两类报错——
  malformed（数字前缀非 14 位时间戳含 8 位纯日期风、14 位裸版本号缺名字段：Phinx 会照常
  加载且前缀即版本号，撞号高危）、ignored（不匹配 Phinx 文件名正则：静默忽略永不执行，
  造成已迁移假象）；归属口径与撞号一致（只报本包目录前缀下的文件）；
- **「年月日+000000」存量只计数进 note 不报错**（一刀切报错会让 44 个存量长期红屏失去
  门禁信号；新建禁止，运行时已有警告，存量逐步 migrate:create 重建）；
- **RULES['migration'] 标题/描述同步** + 种子更新迁移
  （20260909131325_update_audit_migration_rule：按 name 定位更新 radmin_dev_audit_rule
  规则行，缺行补插，幂等；migrate:create --pkg=webman-dev 生成，精确到秒 dogfood）。

## [v3.16.1] - 2026-09-09

### 修复：dto_contract 误报收敛到 public 方法作用域

- 手拼数组检测（success 载荷 + 列表项）限定命中行位于 public 方法体内——私有/保护
  方法里的协议转换中间格式（ai Responses↔Chat 线格式互转 6 处实案）不是对外契约，
  DTO 化属过度设计；全域 27 包审计经此收敛后 ALL_GREEN。

## [v3.16.0] - 2026-09-09

### 增强：审计引擎覆盖面与规则合理性修复（默认包全量 + 四项误报收敛）

- **默认包清单补齐至全部 src/* 基础设施包**（+9：ai-client/asset-client/http/
  infrastructure/knowledge-client/memory-client/happ-client/experiment/slides），
  与 check-version-sync.sh 登记口径对齐，CLI/MCP/后台共用。
- **migration 规则纳入 dev 宿主工程迁移目录**（dev/*/database/{migrations,
  pg-migrations}）：Phinx 装载项目 + 包两类目录，「包 vs 宿主工程」撞号同样阻断
  migrate:run（knowledge 20260908010000 与 cc-knowledge 工程迁移撞号实案）。
- **controller 规则支持包内继承链解析**：extends 链最终到达 Backend 即合规
  （radmin LedgerLog 抽象基类 -> Backend 先例），抽象基类整体豁免（无自身路由）；
  permission 规则同步豁免抽象基类。
- **permission 规则连字符等价归一**：webman 路由 kebab→驼峰方法等价
  （memory/snapshot/session-detail 按钮 ≡ sessionDetail 方法），比对双向
  小写+去连字符，消除 6 条误报。
- **version 规则扫描全部 dev 宿主钉版**：OIDC/happ/experiment/slides 等专属
  宿主钉版此前不可见（只读 dev/full → 误 SKIP），现按全部宿主收集比对。
- **common_utils 规则限定适用域**：仅约束 require rocareer/radmin 的包——纯
  PHP SDK（ai-client/http 等）不可能调用 radmin 全局函数，直接跳过。

## [v3.15.6] - 2026-09-09

### 修复：migration 审计漏扫与跨包时间戳冲突假绿

- 修正迁移文件匹配由误写的 15 位 glob 改为 14 位时间戳正则；此前标准
  `YYYYMMDDHHMMSS_name.php` 全部漏扫，migration 规则始终显示 `0 files` 假绿。
- 扫描范围由当前包单一 `database/migrations` 扩展为整个源码根下所有包的
  `database/{migrations,pg-migrations}`，与 webman-migration `migrate:run --set=all`
  的真实装载范围一致；按时间戳聚合并在所涉及包中只报告一次，兼容定向审计且避免刷屏。
- 首次真实扫描即检出多组存量跨包撞号，证明此前门禁存在假绿；后续包组合进入
  宿主前可由审计提前阻断。
- 修正 composer.json 作者主页为空导致 `composer validate` schema 校验失败。

## [v3.15.5] - 2026-09-07

### 修复：audit 项目/规则列表排序时 500（生成器 index 顺序缺陷同款）

- `audit/AuditProject::index`、`audit/AuditRule::index` 原先 `paginate()` 后
  `applyOrderBy($res)` ——LengthAwarePaginator 无 orderBy 方法，点击列排序即
  500；改排序先于 paginate 应用（与 radmin trait queryList 顺序一致；radmin
  v5.2.2 同步修复生成器 index.stub 源头）

## [v3.15.4] - 2026-09-07

### 修复：dev「开发和调试」目录兜底权重 82 → -1（对齐 radmin v5.1.4 垫底）

- 审计页迁移（20261028120000）与审计分类迁移（20261203120000）的
  ensureDevDir 兜底原写 weigh=82（排在业务菜单中部）；radmin v5.1.4 起 dev
  目录统一 weigh=-1 垫底，本包兜底同步对齐（兜底仅在目录不存在时触发，
  已装环境由 radmin 垫底迁移纠正）。

## [v3.15.3] - 2026-09-07

### 加固：make-crud/crud_generate 深度健壮性（主键语义/注入安全/冲突保护/错误可读）

- **主键语义定版**：仅支持 id 主键（全家桶模型/控制器按 Eloquent getKeyName=id 设计）——
  用户显式声明 id 字段时强制提升为 bigint identity 主键（不再重复注入双 id）；显式
  primary_key 非 id 直接报错提示（此前会生成无序列主键/双 id 列致建表失败）
- **注入安全**：迁移渲染表 comment 进 PHP 单引号字面量前 addslashes（含 ' \ 时断语法）；
  primary_key 动态取实际主键名（防御性，不再硬编码 'id'）；字段重名显式拦截
- **lang 路径层级修正**：targetFiles 语言包路径对齐引擎实际落点（web/src/lang/backend/zh-cn/{path}.ts）
  ——冲突预检此前漏检 lang 文件（重生成会覆盖已改语言包）
- **冲突预检前置 + MCP force 参数**：CLI/MCP 均先查目标文件（controller/model/validate/
  views/lang 7 件套）再落盘（失败零残留）；MCP 缺省保护（force=true 覆盖），与 CLI 对齐
- **MCP 错误可读**：设计不合法/冲突返回 TOOL_VALIDATION_ERROR + 可读 display_message
  （此前抛异常被 McpRegistry 归一为无消息 INTERNAL_ERROR）；inputSchema 移除误导性 primary_key
- **warnings 提示**：select/radio/checkbox/selects 字段 comment 缺字典（页面选项空）时
  CLI/MCP 输出黄色提示（非阻断）；提取 CrudDesigner::targetFiles 供三通道同源探测

## [v3.15.2] - 2026-09-07

### 修复：表名目录语义对齐引擎 + 迁移复用 + 冲突探测同源（make-crud/MCP 定版行为）

- **表名下划线 = 页面/菜单目录层级**（对齐后台 /admin/crud 引擎 parseNameData 语义）：
  cc_student → 控制器 app/admin/controller/cc/Student.php、页面 web/src/views/backend/cc/student/、
  菜单 cc/student——移除错误的 module 参数（此前显式拼路径导致 vue 落 demo/demo/student 双目录）
- 冲突探测/generatedFiles 摘要改为与引擎同源推导（表名拆路径 + 末段 Camel）
- 迁移文件同表名复用：已存在 `*_<table>_crud.php` 则跳过写新文件（防重复运行产生冗余迁移）
- CrudDesigner/README/MCP inputSchema 同步（design_type 白名单注释、目录约定说明）

## [v3.15.1] - 2026-09-07

### 修复：switch 字段类型 int 化（防 PG boolean 分叉，迁移与引擎 DDL 同构）

- CrudDesigner switch 字段 type 由 tinyint 改 int：radmin 引擎 getPhinxFieldType 对
  tinyint + default='1' 会落 PG boolean（true/false），而字典/渲染按 0/1 —— 列表状态列
  显示空白；统一 int 后迁移渲染（integer 0/1）与引擎 DDL 兜底完全同构，全链路一致

## [v3.15.0] - 2026-09-07

### 新增：rocareer:make-crud + MCP crud_generate（标准 CRUD 模块生成，复用 radmin v5.1.0 引擎）

- **rocareer:make-crud 命令**：简化表设计 JSON（字段键极简，字典编码在 comment）-> 渲染 PG 幂等
  迁移文件（database/migrations/<ts>_<table>_crud.php，hasTable 守卫可 migrate:run 追溯）
  + 调 radmin `app\admin\service\CrudService`（v5.1.0 抽取，与后台 /admin/crud 同一引擎）生成
  控制器/模型/验证器 + 前端 index.vue/popupForm.vue + 语言包 + 菜单（幂等种入）；主键 id 与
  create_time/update_time 自动注入；模块段 table.module 自定义代码落点；冲突预检（--force 覆盖）
- **MCP crud_generate 工具**：新集合 CrudCollection（key=crud，子端点 /mcp/crud），参数平铺
  （table_name/table_comment/module/quick_search/fields/no_migration），event.php 同事件双监听注册；
  AI 客户端可直调生成标准模块（与 CLI 同一 CrudDesigner + CrudService）
- **config 真源回归**：command.php/app.php 补回包源码 config/plugin/rocareer/webman-dev/
  （历史仅存宿主侧，新装宿主缺命令注册），随 Install pathRelation 自动落盘
- 依赖：radmin v5.1.0+（CrudService；class_exists 守卫给出升级提示）；design_type 白名单
  input/textarea/editor/switch/select/radio/selects/checkbox/number/float/datetime/date/image/images/file/files/weigh

## [v3.14.2] - 2026-09-07

### 修复（audit）：think 残留门禁补 scene 设置器残留拦截

- orm_migrated 新增 `->scene('` 与 `->scene($` 模式（radmin BaseValidate 已无 scene() 设置器，
  残留调用落官方 __call → "Validator method not found: scene" 运行期崩；v5.0.1 只修字面形态，
  user/Index 变量形态漏网致前台登录全挂，本轮补门禁防再漏；内部合法 getter `$this->scene()` 无参不匹配）

## [v3.14.1] - 2026-09-07

### 修复（audit）：think 残留门禁全收口（随 radmin v5.0.0 联动）

- orm_migrated 规则升级为「think 生态残留门禁」：新增禁止 use think\Validate / use think\Facade /
  think\facade\Validate / think\exception\ValidateException 引用（think-validate/think-container 类
  已随 radmin v5.0.0 移除，残留即崩）；描述移除 think-validate/think-helper/think-container 白名单
- composer 检查泛化为 topthink/* 禁装（替代仅 webman/think-orm）

## [v3.14.0] - 2026-09-07

### 兼容（chore）：放宽 rocareer/radmin 依赖约束至 ^3.1 || ^4.0 || ^5.0

- 配合 radmin v5.0.0（验证框架切换 webman/validation、think 生态彻底清零）联动升级；本包无功能改动。

# Changelog

## [v3.13.1] - 2026-09-05

### 修复：audit event_standard 事件收集正则（动态 $listeners 赋值从未被识别）

- 形态 2 正则（`$listeners['x'] = [`）在双引号字符串中 `\$listeners` 被解析为
  `$listeners`（正则里 `$` 变行尾锚点）→ 动态事件收集从未命中，webman-dev 的
  `mcp.collections.register` 监听被误报「无监听器（发射即空转）」；
  改为 `\\$listeners` 转义后正常命中（mcp 误报消除）。

## [v3.13.0] - 2026-08-31

### 新增：rocareer:audit install_standard 规则 + Install.php 标准化

- 新增 `install_standard` 审计规则（Install.php 标准化门禁，依据 docs/install-standard.md）：
  WEBMAN_PLUGIN 常量 / install/update/uninstall 三钩子齐全 / install 签名兼容官方
  Install::install(true)（禁强类型参数）/ 禁官方骨架残留 copy_dir/remove_dir（显式
  overwrite=true 放行）与 array() 语法 / 类前中文头注释；`@audit-ignore install_standard`
  显式豁免；MCP quality_audit 工具 codes 列表同步。
- Install.php 标准化：补 update() 钩子、install($isFirst = true) 签名、自实现
  installByRelation(bool $isFirst)/uninstallByRelation()（去 copy_dir/remove_dir 骨架残留；
  更新仅补齐缺失项，保留宿主 audit_root 用户配置）。

## [v3.12.1] - 2026-08-31

### 优化：php -l 语法检查并行化（全量审计 49s → 8.6s）

- checkPhpSyntax 由 xargs -n1 串行逐文件 php -l（487 文件 = 487 次进程启动）改为 -P 并行（CLI 按 CPU 核数 nproc/sysctl 检测，常驻进程固定 8），耗时 -86%；
- 新增 syntaxJobs() 核数检测（macOS sysctl / Linux nproc，兜底 8，上限 32；fiber 协程上下文不做阻塞式 shell 检测）。

## [v3.12.0] - 2026-08-31

### 新增：common_utils 通用工具真源门禁（禁止重复造轮子）

- `rocareer:audit` 新增 `common_utils` 门禁（见 docs/common-utils-registry.md）：
  检测已知手写重复模式必须用 radmin 全局函数——max(1,min(100→clamp_limit、分页→clamp_page、
  keyword/quickSearch→request_keyword、where 闭包多字段 like→keyword_like、
  json_encode(UNICODE|SLASHES)→json_unicode、strtr(base64_encode→base64url_encode、
  md5(uniqid→uuid7、固定四星掩码→mask_secret；
  真源定义文件（radmin functions.php）与审计引擎自身源文件豁免；文件标注
  `@audit-ignore common_utils` 显式豁免。

## [v3.11.9] - 2026-08-31

### 修复：orm_migrated 审计补 :: 静态形态扫描（think 专属静态调用防回归）

- AuditService `orm_migrated` 门禁新增 `::order(/::field(/::alias(/::column(/::whereLike(`、
  `::select()` 无参、`::find()` 无参等静态形态扫描（Eloquent 无此形态会
  BadMethodCallException 或静默失效，2026-08-31 曾漏检 DriverService/ChatService）。



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

## [v3.9.0] - 2026-08-29

### 新增审计规则：ORM 迁移门禁（orm_migrated）

- `rocareer:audit` 新增 `orm_migrated` 规则：src 内禁止 think-orm 类引用
  （think\facade\Db / think\db\exception / think\model\relation / think\Paginator /
  think\File / think\Exception）与 `config('think-orm...')` 调用、composer 依赖 webman/think-orm；
  白名单保留 think-validate / think-helper / think-container 类；`@audit-ignore orm_migrated` 显式豁免
- 幂等种子迁移 `20261206060000_radmin_webman_dev_audit_orm_migrated_rule.php`（后台规则列表同步）
- RocareerPlugin `think\File` → `SplFileInfo`（think-orm 卸载适配）；DevCover 映射目标改
  `support\orm\Db` 并标注豁免；18 包全量扫描 0 问题
- 随 radmin v4.0.0 批次发版

## [v3.8.1] - 2026-08-29

### 修复：审计规则种子迁移排序（全 PG 迁移通道前置）
- `radmin_webman_dev_audit_dto_contract_rule` 文件名时间戳 20260828150000 → 20261028125000：
  此前先于建表迁移 `radmin_webman_dev_audit_page`（20261028120000）执行，全新库
  （MySQL 或 PG）会因 `ra_radmin_dev_audit_rule` 不存在而失败；改名后置于建表之后、
  其余规则种子之前。老库 phinxlog 已记录旧版本号，重跑时按 name 去重幂等，无重复数据。

## [v3.8.0] - 2026-08-29

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

## [v3.6.1] - 2026-08-28

### 修复

- **v3.6.0 迁移兼容修复**：`20261203130000` 迁移改用 `execute($sql, $params)` PDO 参数绑定
  更新规则说明——原 `getAdapter()->quote()` 在 Phinx `TimedOutputAdapter` 包装下不存在，
  导致 `migrate:run` 报「Call to undefined method TimedOutputAdapter::quote()」；现可正常
  在 cc-knowledge 等宿主执行。幂等逻辑不变（description 一致时跳过）。

## [v3.6.0] - 2026-08-28

### 新增：前端按钮样式门禁（web_page 规则第 6 项）

- web_page 规则新增检查：**TableHeader 顶部自定义按钮必须使用标准样式类 `table-header-operate`**
  （与 refresh/add/delete 等内置按钮同款，禁止自创类名/裸 `<i>` 图标/`&nbsp;` 拼接）。
  2026-08-28 实战：审计项目页自创 `table-header-audit-run` 非标样式、diancan 桌台页裸图标——
  全域排查后两处均已修复；规则作为防回潮门禁，静态扫描各包 `web/src/views/backend`。
- 迁移 `20261203130000_radmin_webman_dev_audit_web_page_button_rule`（幂等）更新
  web_page 规则说明，后台「审计规则」页描述与引擎元数据同步，需 `migrate:run`。

## [v3.5.1] - 2026-08-28

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

## [v3.4.1] - 2026-08-28

### 修复

- **无 mcp 宿主崩溃**：`config/plugin/rocareer/webman-dev/event.php` 的
  `mcp.collections.register` 监听在未安装 rocareer/mcp 的宿主上会因类加载触发 Fatal
  （AuditCollection implements mcp 接口，interface 不存在）；改为 `interface_exists`
  守卫后仅在有 mcp 的宿主注册监听（实证：dev/diancan 仅装 radmin+diancan 的宿主可正常
  migrate:run / start）。

## [v3.4.0] - 2026-08-27

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

## [v3.3.1] - 2026-08-27

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
