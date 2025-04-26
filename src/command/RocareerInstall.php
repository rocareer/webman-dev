<?php

namespace Rocareer\WebmanDev\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class RocareerInstall extends Command
{
    protected static $defaultName = 'rocareer:export';
    protected static $defaultDescription = 'RocareerExport';

    /**
     * @return void
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


        deleteDirectory(base_path().'/plugin/radmin/');

        copy_dir(base_path().'/vendor/rocareer/radmin/src/plugin/radmin/',base_path().'/plugin/radmin/');

        $output->writeln('Hello RocareerExport');
        return self::SUCCESS;
    }

}
