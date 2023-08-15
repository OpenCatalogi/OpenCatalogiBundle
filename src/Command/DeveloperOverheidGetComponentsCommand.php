<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the DeveloperOverheidService.
 */
class DeveloperOverheidGetComponentsCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:developeroverheid:components';

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $devOverheidService;


    /**
     * @param DeveloperOverheidService $devOverheidService developer Overheid Service
     */
    public function __construct(DeveloperOverheidService $devOverheidService)
    {
        $this->devOverheidService = $devOverheidService;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi DeveloperOverheidService')
            ->setHelp('This command allows you to get all components or one component from developer.overheid.nl/apis')
            ->addOption('component', 'c', InputOption::VALUE_OPTIONAL, 'Get a single component by id');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = [
            'source'           => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
            'componentMapping' => 'https://developer.overheid.nl/api/oc.developerOverheidComponent.mapping.json',
            'componentSchema'  => 'https://opencatalogi.nl/oc.component.schema.json',
            'endpoint'         => '/apis',
        ];

        // Handle the command options
        $componentId = $input->getOption('component', false);

        if ($componentId === null) {
            if (empty($this->devOverheidService->getComponents([], $configuration)) === true) {
                return Command::FAILURE;
            }
        }

        if ($componentId !== null
            && empty($this->devOverheidService->getComponents([], $configuration, $componentId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
