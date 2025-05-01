<?php
namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class DevCover extends Command
{
    protected static $defaultName = 'dev:cover';
    protected static $defaultDescription = 'dev cover';

    /**
     * @return void
     */
    protected function configure()
    {
        // 配置参数（可选）
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 定义要操作的目录
        $directory = radmin_base();

        if (!is_dir($directory)) {
            $output->writeln("<error>Invalid directory: {$directory}</error>");
            return self::FAILURE;
        }

        $output->writeln("Searching in directory: {$directory}");

        // 定义需要查找和替换的内容
        $contents = [
            'use think\facade\Db;'=>'use support\think\Db;',
            '): void' => ')',
            'namespace app\admin\controller' => 'namespace plugin\radmin\app\admin\controller',
            'use app\admin\model\AdminLog;' => 'use plugin\radmin\app\admin\model\AdminLog;',
            'use app\common\controller\Backend;' => 'use plugin\radmin\app\common\controller\Backend;',
            'namespace app\admin\controller;' => 'namespace plugin\radmin\app\admin\controller;',
            'use ba\Terminal;'=> 'use plugin\radmin\extend\ba\Terminal;',
            '   $this->success(' => 'return $this->success(',
            '   $this->error(' => 'return $this->error(',
            'return return $this->success(' => 'return $this->success(',
            'use ba\TableManager;' => 'use plugin\radmin\extend\ba\TableManager;',
            'use app\common\library\Upload;' => 'use plugin\radmin\app\common\library\Upload;',
            'namespace app\api\controller;' => 'namespace plugin\radmin\app\api\controller;',
            'use app\common\controller\Frontend;' => 'use plugin\radmin\app\common\controller\Frontend;',
            'use app\api\validate\\' =>'use plugin\radmin\app\api\validate\\',
            'use app\common\facade\Token;'=>'',
            'use ba\ClickCaptcha;'=>'use plugin\radmin\extend\ba\ClickCaptcha;',
            'use ba\Captcha;'=>'use plugin\radmin\extend\ba\Captcha;',
            'use plugin\radmin\app\common\facade\Token;'=>'',
            '$this->auth->id'=>'$this->request->auth->id',
            '* @return void'=>'',
            'Config::get('=>'radmin_config('

            // 添加更多搜索和替换内容
        ];

        $totalReplacements = 0; // 统计总替换次数

        // 遍历内容并执行查找和替换
        foreach ($contents as $search => $replace) {
            $replacements = $this->searchAndReplace($directory, $search, $replace, $output);
            $totalReplacements += $replacements; // 累加替换次数
        }

        $output->writeln("<info>Search and replace completed! Total replacements: {$totalReplacements}</info>");
        return self::SUCCESS;
    }

    /**
     * Recursively search and replace content in files.
     *
     * @param string $directory
     * @param string $search
     * @param string $replace
     * @param OutputInterface $output
     * @return int // 返回替换次数
     */
    private function searchAndReplace(string $directory, string $search, string $replace, OutputInterface $output): int
    {
        $files = scandir($directory);
        $replacementCount = 0; // 统计当前搜索的替换次数

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                // 递归处理子目录
                $replacementCount += $this->searchAndReplace($filePath, $search, $replace, $output);
            } elseif (is_file($filePath)) {
                // 处理文件
                $content = file_get_contents($filePath);
                if (strpos($content, $search) !== false) {
                    // 找到内容
                    $output->writeln("<info>Found in file: {$filePath} $search</info>");
                    $newContent = str_replace($search, $replace, $content);

                    // 仅在内容确实改变时才写入文件
                    if ($newContent !== $content) {
                        file_put_contents($filePath, $newContent);
                        $replacementCount++; // 增加替换计数
                        $output->writeln("<info>Replaced in file: {$filePath}</info>");
                    } else {
                        $output->writeln("<comment>No change in file: {$filePath}</comment>");
                    }
                }
            }
        }

        return $replacementCount; // 返回当前替换次数
    }

}
