<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
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
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param DeveloperOverheidService $devOverheidService developer Overheid Service
     * @param GatewayResourceService   $resourceService    The Gateway Resource Service
     */
    public function __construct(
        DeveloperOverheidService $devOverheidService,
        GatewayResourceService $resourceService
    ) {
        $this->devOverheidService = $devOverheidService;
        $this->resourceService    = $resourceService;
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
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $developerAction = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.ComponentenCatalogusComponentToGatewayAction.action.json', 'open-catalogi/open-catalogi-bundle');
        $configuration   = $developerAction->getConfiguration();

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
