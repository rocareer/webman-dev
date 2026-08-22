# Changelog

## v3.0.0 - 2026-08-30

### 新增 rocareer:audit 代码规范审计命令

- 控制器规范检查：继承 Backend、: Response 签名（?Response/return null 报错）、initialize 调 parent、public 方法返回类型
- 权限节点与 routePath 匹配（核心）：按 radmin Request::controller() 规则计算每个控制器方法的 routePath
  （类名末两段小写 + '/' + 方法名小写，全小写无连字符），与迁移中注册的按钮名比对，
  缺失/错名/孤儿按钮全部报出（2026-08-30 深度审计发现 ai/channel/agent 等包连字符按钮对非超管 401，即此检查修复）
- 迁移时间戳查重（Phinx 全局排序，撞号致全家桶 migrate 无法执行）
- 残留扫描：CRUD 脚手架死代码（Test 控制器/模型/验证器）、TODO/FIXME 计数
- 版本同步：CHANGELOG 头部版本 vs demo/full composer.json path 钉版
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
