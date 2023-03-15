<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\ComponentenCatalogusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class ComponentenCatalogusGetApplicationsCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:componentencatalogus:applications';

    /**
     * @var ComponentenCatalogusService
     */
    private ComponentenCatalogusService  $compCatService;

    /**
     * @param ComponentenCatalogusService $compCatService componenten Catalogus Service
     */
    public function __construct(ComponentenCatalogusService $compCatService)
    {
        $this->compCatService = $compCatService;
        parent::__construct();
    }//end __construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi ComponentenCatalogusService')
            ->setHelp('This command allows you to get all applications or one application from componentencatalogus.commonground.nl/api/products')
            ->addOption('application', 'a', InputOption::VALUE_OPTIONAL, 'Get a single application by id');
    }//end configure()

    /**
     * @param InputInterface  $input  The input
     *
     * @return int
     */
    protected function execute(InputInterface $input): int
    {
        // Handle the command options.
        $applicationId = $input->getOption('application', false);

        if ($applicationId === null) {
            if ($this->compCatService->getApplications() === null) {
                return Command::FAILURE;
            }
        }

        if ($applicationId !== null 
            && $this->compCatService->getApplication($applicationId) === null
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
