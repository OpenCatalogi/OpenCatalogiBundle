<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

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
    private ComponentenCatalogusService  $componentenCatalogusService;

    /**
     * @param ComponentenCatalogusService $componentenCatalogusService componenten Catalogus Service
     */
    public function __construct(ComponentenCatalogusService $componentenCatalogusService)
    {
        $this->componentenCatalogusService = $componentenCatalogusService;
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
     * @param InputInterface $input The input
     * @param OutputInterface $output The output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->componentenCatalogusService->setStyle($io);

        // Handle the command optiosn
        $applicationId = $input->getOption('application', false);

        if ($applicationId === false) {
            if (!$this->componentenCatalogusService->getApplications()) {
                return Command::FAILURE;
            }
        } else if (!$this->componentenCatalogusService->getApplication($applicationId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
