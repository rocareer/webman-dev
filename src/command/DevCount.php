<?php
/*
 *
 *  * // +----------------------------------------------------------------------
 *  * // | Rocareer [ ROC YOUR CAREER ]
 *  * // +----------------------------------------------------------------------
 *  * // | Copyright (c) Rocareer Team. All rights reserved.
 *  * // +----------------------------------------------------------------------
 *  * // | Author: albert@rocareer.com
 *  * // +----------------------------------------------------------------------
 *  * // | Author: albert <albert@rocareer.com>
 *  * // +----------------------------------------------------------------------
 *
 */
namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dev:count 命令——统计/查看已加载类、函数数量与内存消耗等开发信息。
 *
 * 输出当前进程预加载的类数、用户自定义函数数与内存占用（MB），
 * 用于开发时快速查看常驻进程的加载规模；dev:status 侧重环境状态与类/函数搜索，
 * 本命令侧重数量统计与内存信息，二者定位互补。
 */
class DevCount extends Command
{
    protected static $defaultName = 'dev:count';
    protected static $defaultDescription = '统计已加载类/函数与内存消耗';

    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln('dev:count 已加载类与内存统计');

        echo "预加载类: \n";

        $funcs = get_defined_functions();
        $class = sizeof(get_declared_classes());
        $funcs = sizeof($funcs['user']);

        //保留5 位 小数点

        $mem = sprintf("%.5f", (memory_get_usage() / 1024 / 1024));

        $output->writeln("预加载类   : $class");
        $output->writeln("预加载函数 : $funcs");
        $output->writeln("内存消耗   : $mem M");
        $output->writeln(date_default_timezone_get());

        return self::SUCCESS;
    }
}
