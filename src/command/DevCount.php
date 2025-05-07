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

use support\think\Db;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class DevCount extends Command
{
    protected static $defaultName = 'dev:count';
    protected static $defaultDescription = 'dev count';

    /**
     
     */
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
        $output->writeln('Hello dev count');
	    
	    
	    echo "预加载类: \n";
		
		$funcs = get_defined_functions();
//		print_r($funcs);
		$class = sizeof(get_declared_classes());
		$funcs=sizeof($funcs['user']);
	    print_r(get_declared_classes());
		
	    //保留5 位 小数点
	    
	    $mem=sprintf("%.5f",(memory_get_usage() / 1024 / 1024));
		
		$output->writeln("预加载类   : $class");
		$output->writeln("预加载函数 : $funcs");
		$output->writeln("内存消耗   : $mem M");
		$output->writeln(date_default_timezone_get());
//		print_r(get_defined_functions());
//	    echo ;
		
        return self::SUCCESS;
    }

}
