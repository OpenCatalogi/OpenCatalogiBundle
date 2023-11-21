<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use OpenCatalogi\OpenCatalogiBundle\Service\ComponentenCatalogusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
    private ComponentenCatalogusService $compCatService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param ComponentenCatalogusService $compCatService  componenten Catalogus Service
     * @param GatewayResourceService      $resourceService The Gateway Resource Service
     */
    public function __construct(
        ComponentenCatalogusService $compCatService,
        GatewayResourceService $resourceService
    ) {
        $this->compCatService  = $compCatService;
        $this->resourceService = $resourceService;
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
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $componentenAction = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.ComponentenCatalogusApplicationToGatewayAction.action.json', 'open-catalogi/open-catalogi-bundle');
        $configuration     = $componentenAction->getConfiguration();

        // Handle the command options.
        $applicationId = $input->getOption('application', false);

        if ($applicationId === null) {
            if ($this->compCatService->getApplications([], $configuration) === null) {
                return Command::FAILURE;
            }
        }

        if ($applicationId !== null
            && $this->compCatService->getApplications([], $configuration, $applicationId) === null
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
