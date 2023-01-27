<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the DeveloperOverheidService
 */
class DeveloperOverheidGetComponentsCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'opencatalogi:developeroverheid:components';
    private DeveloperOverheidService  $developerOverheidService;


    public function __construct(DeveloperOverheidService $developerOverheidService)
    {
        $this->developerOverheidService = $developerOverheidService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi DeveloperOverheidService')
            ->setHelp('This command allows you to get all components or one component from developer.overheid.nl/apis')
            ->addOption('component', 'c', InputOption::VALUE_OPTIONAL, 'Get a single component by id or name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->developerOverheidService->setStyle($io);

        // Handle the command optiosn
        $componentId = $input->getOption('component', false);

        if(!$componentId){
            $this->developerOverheidService->getComponents();
        } else{
            $this->developerOverheidService->getComponent($componentId);
        }

        return 0;
    }
}
