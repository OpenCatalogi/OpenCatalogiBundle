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
class ComponentenCatalogusGetComponentsCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console").
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:componentencatalogus:components';

    /**
     * @var ComponentenCatalogusService
     */
    private ComponentenCatalogusService  $componentenCatalogusService;

    /**
     * @param ComponentenCatalogusService $componentenCatalogusService ComponentenCatalogusService
     */
    public function __construct(ComponentenCatalogusService $componentenCatalogusService)
    {
        $this->componentenCatalogusService = $componentenCatalogusService;
        parent::__construct();
    }//end construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi ComponentenCatalogusService')
            ->setHelp('This command allows you to get all components or one component from componentencatalogus.commonground.nl/api/components')
            ->addOption('component', 'c', InputOption::VALUE_OPTIONAL, 'Get a single component by id');
    }//end configure()

    /**
     * @param InputInterface  $input  The style input
     * @param OutputInterface $output The style output
     *
     * @return int The result of this command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->componentenCatalogusService->setStyle($style);

        // Handle the command optiosn
        $componentId = $input->getOption('component', false);

        if (!$componentId) {
            if (!$this->componentenCatalogusService->getComponents()) {
                return Command::FAILURE;
            }
        } elseif (!$this->componentenCatalogusService->getComponent($componentId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
