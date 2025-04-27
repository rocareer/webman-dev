<?php
/*
 *
 *  * // +----------------------------------------------------------------------
 *  * // | Rocareer [ ROC YOUR CAREER ]
 *  * // +----------------------------------------------------------------------
 *  * // | Copyright (c) 2014~2025 Albert@rocareer.com All rights reserved.
 *  * // +----------------------------------------------------------------------
 *  * // | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  * // +----------------------------------------------------------------------
 *  * // | Author: albert <Albert@rocareer.com>
 *  * // +----------------------------------------------------------------------
 *
 */

namespace Rocareer\WebmanDev\command;

use Rocareer\Support\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use think\File;

class RocareerPlugin extends Command
{
    protected static $defaultName = 'rocareer:plugin';
    protected static $defaultDescription = 'Synchronize test1 and test directories using Filesystem';



    protected $dirs = [


    ];
    protected static $configPath=[
        'config/plugin/rocareer/'=>'vendor/rocareer/radmin/src/config/plugin/rocareer/'
    ];
    protected static $pluginPath=[
        'plugin/' => 'vendor/rocareer/radmin/src/plugin/',
    ];

    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Rocareer plugin name');
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Force deletion without confirmation'
        );
        $this->addOption(
            'install',
            'i',
            InputOption::VALUE_NONE,
            'Install'
        );
        $this->addOption(
            'export',
            'e',
            InputOption::VALUE_NONE,
            'Export'
        );
        $this->addOption(
            'plugin',
            'p',
            InputOption::VALUE_NONE,
            'Default'
        );

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $export = $input->getOption('export');
        $install = $input->getOption('install');
        $plugin=$input->getOption('plugin');
        $dirs=[];
        foreach (self::$configPath as $source=>$target) {
            $dirs['config'] = [
                $source.$name=>$target.$name
            ];
            foreach ($dirs['config'] as $source=> $target) {
                if ($export){
                    $this->sync($source,$target,$input,$output);
                }
                if ($install){
                    $this->sync($target,$source,$input,$output);
                }
            }
        }
        if ($plugin){
            foreach (self::$pluginPath as $source=>$target) {
                $dirs['plugin'] = [
                    $source.$name=>$target.$name
                ];
            }
            foreach ($dirs['plugin'] as $source=> $target) {
                if ($export){
                    $this->sync($source,$target,$input,$output);
                }
                if ($install){
                    $this->sync($target,$source,$input,$output);
                }
            }
        }



        return Command::SUCCESS;
    }

    protected function sync($source,$target,InputInterface $input, OutputInterface $output): int
    {
        try {
            $filesystem = Filesystem::disk('dev');
            $sourceDir = $source;
            $targetDir = $target;

            // 确保目标目录存在
            if (!$filesystem->has($targetDir)) {
                $filesystem->createDir($targetDir);
            }

            // 获取源目录中的所有文件
            $basePath = base_path();
            $sourcePath = $basePath . DIRECTORY_SEPARATOR . $sourceDir;

            if (!is_dir($sourcePath)) {
                throw new \RuntimeException("Source directory does not exist: {$sourcePath}");
            }

            // 收集文件信息
            $sourceFiles = [];
            $targetFiles = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            // 获取源目录文件信息（包括空目录）
            $dirStack = [];
            foreach ($iterator as $file) {
                $relativePath = str_replace($sourcePath, '', $file->getPathname());
                $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                if ($file->isDir()) {
                    // 记录所有目录（包括空目录）
                    $sourceFiles[$relativePath] = [
                        'path' => $relativePath,
                        'is_dir' => true,
                        'empty' => true  // 初始标记为空目录
                    ];
                    $dirStack[] = $relativePath;
                } else {
                    // 记录文件信息
                    $sourceFiles[$relativePath] = [
                        'path' => $relativePath,
                        'size' => $file->getSize(),
                        'mtime' => $file->getMTime(),
                        'is_dir' => false
                    ];

                    // 标记父目录为非空
                    $parentDir = dirname($relativePath);
                    while ($parentDir !== '.') {
                        if (isset($sourceFiles[$parentDir])) {
                            $sourceFiles[$parentDir]['empty'] = false;
                        }
                        $parentDir = dirname($parentDir);
                    }
                }
            }

            // 获取目标目录信息（包括目录）
            $targetPath = $basePath . DIRECTORY_SEPARATOR . $targetDir;
            if (is_dir($targetPath)) {
                $targetIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($targetPath, \FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($targetIterator as $file) {
                    $relativePath = str_replace($targetPath, '', $file->getPathname());
                    $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                    if ($file->isDir()) {
                        $targetFiles[$relativePath] = [
                            'path' => $relativePath,
                            'is_dir' => true
                        ];
                    } else {
                        $targetFiles[$relativePath] = [
                            'path' => $relativePath,
                            'size' => $file->getSize(),
                            'mtime' => $file->getMTime(),
                            'is_dir' => false
                        ];
                    }
                }
            }

            // 分析文件变化
            $changes = [
                'added' => [],
                'modified' => [],
                'unchanged' => []
            ];

            // 检查文件状态
            foreach ($sourceFiles as $path => $sourceInfo) {
                if (!$sourceInfo['is_dir']) {
                    if (isset($targetFiles[$path])) {
                        // 检查文件内容是否相同
                        $sourceContent = md5_file($sourcePath . '/' . $path);
                        $targetContent = md5_file($targetPath . '/' . $path);

                        if ($sourceContent !== $targetContent) {
                            $changes['modified'][] = $path;
                        } else {
                            $changes['unchanged'][] = $path;
                        }
                    } else {
                        $changes['added'][] = $path;
                    }
                }
            }

            // 显示详细的同步报告
            $output->writeln("\n<info>=== Synchronization Report ===</info>");

            // 显示新增文件
            if (!empty($changes['added'])) {
                $count = count($changes['added']);
                $output->writeln("\n<info>New files to be added $count: </info>");
                foreach ($changes['added'] as $file) {
                    $output->writeln("  [+] {$file}");
                }
            } else {
                $output->writeln("\n<info>No new files to add</info>");
            }

            // 显示修改文件
            if (!empty($changes['modified'])) {
                $count = count($changes['modified']);
                $output->writeln("\n<comment>Files to be updated $count: </comment>");
                foreach ($changes['modified'] as $file) {
                    $output->writeln("  [M] {$file}");
                }
            } else {
                $output->writeln("\n<info>No files to update</info>");
            }

            // 显示未修改文件
            if (!empty($changes['unchanged'])) {
                $count = count($changes['unchanged']);
                $output->writeln("\n<fg=gray>Unchanged files $count: </>");
                foreach ($changes['unchanged'] as $file) {
//                    $output->writeln("  [=] {$file}");
                }
            }

            $output->writeln("\n<info>Note: No files will be deleted from target directory.</info>");

            $output->writeln("\n<info>Starting synchronization...</info>");

            // 计算总操作数（目录创建+新增文件+修改文件）
            $totalOperations = count(array_filter($sourceFiles, function ($f) {
                    return $f['is_dir'];
                }))
                + count($changes['added'])
                + count($changes['modified']);

            $progressBar = new ProgressBar($output, $totalOperations);
            $progressBar->start();

            // 1. 创建所有目录结构（包括空目录）
            foreach ($sourceFiles as $path => $fileInfo) {
                if ($fileInfo['is_dir']) {
                    $targetPath = $targetDir . '/' . $path;
                    if (!$filesystem->has($targetPath)) {
                        $filesystem->createDir($targetPath);
                        if ($fileInfo['empty']) {
                            $output->writeln("<info>Created empty directory: {$targetPath}</info>");
                        } else {
                            $output->writeln("<info>Created directory: {$targetPath}</info>");
                        }
                    }
                    $progressBar->advance();
                }
            }

            // 2. 添加新文件
            foreach ($changes['added'] as $path) {
                $sourceFile = new File($sourcePath . '/' . $path);
                $targetParentPath = $targetDir . '/' . dirname($path);

                if (!$filesystem->has($targetParentPath)) {
                    $filesystem->createDir($targetParentPath);
                }

                $filesystem->putFileAs($targetDir, $sourceFile, $path);
                $output->writeln("<info>Added new file: {$path}</info>");
                $progressBar->advance();
            }

            // 3. 更新修改过的文件
            foreach ($changes['modified'] as $path) {
                $sourceFile = new File($sourcePath . '/' . $path);
                $targetParentPath = $targetDir . '/' . dirname($path);

                $filesystem->putFileAs($targetDir, $sourceFile, $path);
                $output->writeln("\n<comment>Updated file: {$path}</comment>");
                $progressBar->advance();
            }

            $progressBar->finish();

            // 删除目标目录中不存在于源目录的内容
            $toDelete = $this->findFilesToDelete($sourceFiles, $targetFiles, $targetPath);

            if (!empty($toDelete['files']) || !empty($toDelete['dirs'])) {
                $output->writeln("\n<comment>Items to be deleted in target directory:</comment>");

                foreach ($toDelete['files'] as $file) {
                    $output->writeln("  <error>- File: {$file}</error>");
                }

                foreach ($toDelete['dirs'] as $dir) {
                    $output->writeln("  <error>- Directory: {$dir}</error>");
                }

                if ($input->getOption('force') || $this->confirmDeletion($input, $output)) {
                    $this->performDeletion($filesystem, $toDelete, $targetDir, $output);
                } else {
                    $output->writeln("\n<info>Deletion cancelled. No files were deleted.</info>");
                }
            } else {
                $output->writeln("\n<info>No files to delete in target directory.</info>");
            }
            $output->writeln('');
            $output->writeln("<info>Directories synchronized successfully.</info>");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>Synchronization failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
    /**
     * 查找需要删除的文件和目录
     */
    protected function findFilesToDelete(array $sourceFiles, array $targetFiles, string $targetPath): array
    {
        $toDelete = ['files' => [], 'dirs' => []];

        // 找出需要删除的文件
        foreach ($targetFiles as $path => $info) {
            if (!$info['is_dir'] && !isset($sourceFiles[$path])) {
                $toDelete['files'][] = $path;
            }
        }

        // 找出需要删除的目录（自底向上）
        $dirsToCheck = array_filter($targetFiles, function ($f) {
            return $f['is_dir'];
        });
        krsort($dirsToCheck); // 反向排序确保先处理子目录

        foreach ($dirsToCheck as $path => $info) {
            if (!isset($sourceFiles[$path])) {
                $toDelete['dirs'][] = $path;
            }
        }

        return $toDelete;
    }

    /**
     * 确认删除操作
     */
    protected function confirmDeletion(InputInterface $input, OutputInterface $output): bool
    {
        $question = new ConfirmationQuestion(
            '<question>Are you sure you want to delete these items? (y/n)</question> ',
            false
        );

        return $this->getHelper('question')->ask($input, $output, $question);
    }

    /**
     * 执行删除操作
     */
    protected function performDeletion(Filesystem $filesystem, array $toDelete, string $targetDir, OutputInterface $output)
    {
        // 先删除文件
        foreach ($toDelete['files'] as $file) {
            try {
                $filesystem->delete($targetDir . '/' . $file);
                $output->writeln("<info>Deleted file: {$file}</info>");
            } catch (\Exception $e) {
                $output->writeln("<error>Failed to delete file {$file}: {$e->getMessage()}</error>");
            }
        }

        // 再删除空目录
        foreach ($toDelete['dirs'] as $dir) {
            try {
                $filesystem->deleteDir($targetDir . '/' . $dir);
                $output->writeln("<info>Deleted directory: {$dir}</info>");
            } catch (\Exception $e) {
                $output->writeln("<error>Failed to delete directory {$dir}: {$e->getMessage()}</error>");
            }
        }
    }


}
