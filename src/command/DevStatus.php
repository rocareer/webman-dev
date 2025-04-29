<?php

namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use support\Log;

class DevStatus extends Command
{
    protected static $defaultName = 'dev:status';
    protected static $defaultDescription = 'Show current PHP environment status';

    protected function configure()
    {
        $this->addOption('h', null, InputOption::VALUE_OPTIONAL, 'Class or function name to search (supports partial match)');
        $this->addOption('f', null, InputOption::VALUE_NONE, 'Show all loaded classes and functions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $logFilePath = runtime_path('logs/R-dev.log');

        // 开始构建输出
        ob_start(); // 开启输出缓冲
        $io->title('PHP Environment Status');
        $io->writeln('PHP Version: ' . phpversion());

        // 显示内存使用情况
        $io->section('Memory Usage');
        $io->writeln('Current: <info>' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB</info>');
        $io->writeln('Peak: <info>' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB</info>');

        // 显示执行时间
        $io->writeln('Execution Time: <info>' . round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 4) . ' seconds</info>');

        // 处理查询参数
        if ($search = $input->getOption('h')) {
            $this->searchClassesAndFunctions($search, get_declared_classes(), get_defined_functions()['user'], $io);
        }

        // 显示所有类和函数信息（如果 -f 参数被使用）
        if ($input->getOption('f')) {
            $this->showAllClassesAndFunctions(get_declared_classes(), get_defined_functions()['user'], $io);
            $this->showPhpConfiguration($io); // 仅在 -f 时显示配置
        }

        // 最后统一显示统计数量
        $this->showSummary($io);

        // 获取输出内容并记录到日志
        $outputContent = ob_get_clean(); // 获取输出缓冲内容
        Log::info($outputContent); // 写入日志文件

        return self::SUCCESS;
    }

    protected function searchClassesAndFunctions(string $search, array $classes, array $functions, SymfonyStyle $io): void
    {
        $io->section("Searching for: <info>$search</info>");

        // 查询类
        $matchedClasses = array_filter($classes, function ($class) use ($search) {
            return stripos($class, $search) !== false; // 支持模糊查询
        });

        // 查询函数
        $matchedFunctions = array_filter($functions, function ($function) use ($search) {
            return stripos($function, $search) !== false; // 支持模糊查询
        });

        // 输出结果
        if (count($matchedClasses) > 0) {
            $io->writeln('Matched Classes:');
            foreach ($matchedClasses as $class) {
                $io->writeln('- <comment>' . $class . '</comment>');
            }
            $io->writeln('Total Matched Classes: <info>' . count($matchedClasses) . '</info>');
        } else {
            $io->writeln('No matching classes found.');
        }

        if (count($matchedFunctions) > 0) {
            $io->writeln('Matched Functions:');
            foreach ($matchedFunctions as $function) {
                $io->writeln('- <comment>' . $function . '</comment>');
            }
            $io->writeln('Total Matched Functions: <info>' . count($matchedFunctions) . '</info>');
        } else {
            $io->writeln('No matching functions found.');
        }
    }

    protected function showAllClassesAndFunctions(array $classes, array $functions, SymfonyStyle $io): void
    {
        $io->section('All Loaded Classes');
        foreach ($classes as $class) {
            $io->writeln('- <comment>' . $class . '</comment>');
        }

        $io->section('All User-defined Functions');
        foreach ($functions as $function) {
            $io->writeln('- <comment>' . $function . '</comment>');
        }
    }

    protected function showPhpConfiguration(SymfonyStyle $io): void
    {
        $io->section('PHP Configuration');
        $phpConfig = ini_get_all();

        // 需要排除的配置项
        $excludedKeys = [
            'SMTP',
            'smtp_port',
            'soap.wsdl_cache',
            'soap.wsdl_cache_dir',
            'soap.wsdl_cache_enabled',
        ];

        // 过滤配置项
        foreach ($excludedKeys as $key) {
            unset($phpConfig[$key]);
        }

        // 输出过滤后的配置项
        $io->writeln(print_r($phpConfig, true));
    }

    protected function showSummary(SymfonyStyle $io): void
    {
        $classes = get_declared_classes();
        $functions = get_defined_functions()['user'];
        $extensions = get_loaded_extensions();

        $io->section('Summary');
        $io->writeln('Total Loaded Classes: <info>' . count($classes) . '</info>');
        $io->writeln('Total User-defined Functions: <info>' . count($functions) . '</info>');
        $io->writeln('Total Loaded Extensions: <info>' . count($extensions) . '</info>');
    }
}
