<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindOrganizationThroughRepositoriesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindOrganizationThroughRepositoriesService.
 */
class FindOrganizationThroughRepositoriesCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:findOrganizationThroughRepositories:execute';

    /**
     * @var FindOrganizationThroughRepositoriesService
     */
    private FindOrganizationThroughRepositoriesService  $findOrganizationThroughRepositoriesService;

    /**
     * @param FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService
     */
    public function __construct(FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService)
    {
        $this->findOrganizationThroughRepositoriesService = $findOrganizationThroughRepositoriesService;
        parent::__construct();
    }//end __construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update an organizations with found opencatalogi.yml info')
            ->addOption('repositoryId', 'r', InputOption::VALUE_OPTIONAL, 'Find an organization for a specific repository by id');
    }//end configure()

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findOrganizationThroughRepositoriesService->setStyle($io);

        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if (!$repositoryId) {
            if (!$this->findOrganizationThroughRepositoriesService->findOrganizationThroughRepositoriesHandler()) {
                return Command::FAILURE;
            }
        } elseif (!$this->findOrganizationThroughRepositoriesService->findOrganizationThroughRepositoriesHandler([], [], $repositoryId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
