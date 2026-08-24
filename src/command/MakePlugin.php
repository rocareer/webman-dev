<?php
/**
 * rocareer:make-plugin — 生成符合 radmin 全家桶规范的基础设施插件骨架
 *
 * 用法：php webman rocareer:make-plugin <name> --title=中文名 [--description=...] [--out=路径]
 * 模板：src/command/templates/（{{NAME}}/{{UC}}/{{LCC}}/{{TITLE}}/{{DESC}}/{{TS}}/{{YEAR}} 占位符）
 * 生成：composer.json / CHANGELOG.md / README.md / .gitignore / config/plugin/rocareer/<name>/
 *       database/migrations/<ts>_radmin_<name>_init.php（幂等建表+菜单权限）
 *       src/Install.php / src/app/common.php（<name>_config helper）
 *       src/app/admin/controller/<name>/<Name>.php（Backend 五件套示例）
 *       src/app/admin/model/<Name>.php / src/app/admin/service/<Name>Service.php
 */

namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MakePlugin extends Command
{
    protected static $defaultName = 'rocareer:make-plugin';

    protected static $defaultDescription = 'Scaffold a standard rocareer infrastructure plugin (radmin + webman style)';

    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Plugin name (lowercase, e.g. ai / memory / chat)');
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Chinese menu title');
        $this->addOption('description', null, InputOption::VALUE_REQUIRED, 'Composer description');
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory (default: workspace root next to radmin/)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = strtolower(trim((string) $input->getArgument('name')));
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
            $io->error('Plugin name must be lowercase alphanumeric with dashes (e.g. ai, webman-migration)');
            return self::FAILURE;
        }
        $title = (string) $input->getOption('title') ?: $name;
        $desc = (string) $input->getOption('description') ?: ('Radmin ' . $name . ' plugin: infrastructure for rocareer family');
        $out = $input->getOption('out') ?: ($this->detectRoot() . '/' . $name);
        if (is_dir($out) && glob(rtrim($out, '/') . '/*')) {
            $io->error('Output directory not empty: ' . $out);
            return self::FAILURE;
        }
        if (!is_dir(dirname($out))) {
            $io->error('Output parent directory not found: ' . dirname($out));
            return self::FAILURE;
        }

        $ts = date('YmdHis');
        $uc = $this->camel($name);
        $lcc = $this->lowerCamel($name);
        $tplDir = __DIR__ . '/templates';

        // 模板文件 -> 目标相对路径（key 为模板文件名，value 为目标路径模板）
        $map = [
            'composer.json.tpl' => 'composer.json',
            'CHANGELOG.md.tpl' => 'CHANGELOG.md',
            'README.md.tpl' => 'README.md',
            'gitignore.tpl' => '.gitignore',
            'config_app.php.tpl' => 'config/plugin/rocareer/{{NAME}}/app.php',
            'config_command.php.tpl' => 'config/plugin/rocareer/{{NAME}}/command.php',
            'migration_init.php.tpl' => 'database/migrations/{{TS}}_radmin_{{NAME}}_init.php',
            'Install.php.tpl' => 'src/Install.php',
            'common.php.tpl' => 'src/app/common.php',
            'controller.php.tpl' => 'src/app/admin/controller/{{NAME}}/{{UC}}.php',
            'model.php.tpl' => 'src/app/admin/model/{{UC}}.php',
            'service.php.tpl' => 'src/app/admin/service/{{UC}}Service.php',
        ];

        $vars = [
            '{{NAME}}' => $name,
            '{{UC}}' => $uc,
            '{{LCC}}' => $lcc,
            '{{TITLE}}' => $title,
            '{{DESC}}' => $desc,
            '{{TS}}' => $ts,
            '{{YEAR}}' => date('Y'),
        ];

        foreach ($map as $tplFile => $rel) {
            $tplPath = $tplDir . '/' . $tplFile;
            if (!is_file($tplPath)) {
                $io->error('Template missing: ' . $tplPath);
                return self::FAILURE;
            }
            $content = strtr(file_get_contents($tplPath), $vars);
            $target = strtr($rel, $vars);
            $path = $out . '/' . $target;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }

        $io->success('Plugin scaffold created at ' . $out);
        $io->writeln('Next steps:');
        $io->writeln('  1. cd ' . $out . ' && git init && git add -A && git commit -m "v0.1.0: 首个版本"');
        $io->writeln('  2. gitee 建仓 rocareer/' . $uc . ' 后 git remote add origin ... && git push -u origin master');
        $io->writeln('  3. dev/full/composer.json 注册 path 仓库并钉版（见 README）');
        $io->writeln('  4. cd dev/full && composer update --no-dev && php webman migrate:run && php start.php restart');
        return self::SUCCESS;
    }

    protected function detectRoot(): string
    {
        $dir = getcwd() ?: '.';
        while (true) {
            if (is_dir($dir . '/radmin')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return '.';
            }
            $dir = $parent;
        }
    }

    protected function camel(string $s): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $s)));
    }

    protected function lowerCamel(string $s): string
    {
        return lcfirst($this->camel($s));
    }
}
