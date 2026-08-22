# rocareer/webman-dev

Radmin 全家桶开发工具包：代码规范审计 + 插件脚手架。

## 安装

demo/full/composer.json 注册 path 仓库并钉版（versions: rocareer/webman-dev = 3.0.0），
然后 composer update --no-dev。

## 命令

### rocareer:audit — 基础设施包代码规范审计

    php webman rocareer:audit              # 审计全部包（自动探测工作区根）
    php webman rocareer:audit --pkg=ai     # 只审计 ai
    php webman rocareer:audit --root=/path/to/workspace

检查项：php -l、控制器规范（Backend / : Response / initialize / 注释）、
权限按钮 name 与 routePath 匹配、迁移时间戳查重、脚手架残留、版本钉版同步。
任一 FAIL 时 exit code 非 0，可用于 CI。

### rocareer:make-plugin — 插件脚手架

    php webman rocareer:make-plugin demo --title=演示 --description="..." [--out=/tmp/demo]

生成标准插件骨架后按提示接入 demo 即可。
