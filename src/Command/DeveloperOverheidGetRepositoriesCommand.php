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
class DeveloperOverheidGetRepositoriesCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:developeroverheid:repositories';

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
            ->setHelp('This command allows you to get all repositories or one repository from developer.overheid.nl/repositories')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Get a single repository by id');

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
            'repositorySchema' => 'https://opencatalogi.nl/oc.repository.schema.json',
            'endpoint'         => '/repositories',
        ];
        // Handle the command options
        $repositoryId = $input->getOption('repository', false);

        if ($repositoryId === null) {
            if (empty($this->devOverheidService->getRepositories([], $configuration)) === true) {
                return Command::FAILURE;
            }
        }

        if ($repositoryId !== null
            && empty($this->devOverheidService->getRepositories([], $configuration, $repositoryId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
