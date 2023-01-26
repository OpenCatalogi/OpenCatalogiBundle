<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindOrganizationThroughRepositoriesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindOrganizationThroughRepositoriesService
 */
class FindOrganizationThroughRepositoriesCommand extends Command
{
    protected static $defaultName = 'opencatalogi:findOrganizationThroughRepositories:execute';
    private FindOrganizationThroughRepositoriesService  $findOrganizationThroughRepositoriesService;


    public function __construct(FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService)
    {
        $this->findOrganizationThroughRepositoriesService = $findOrganizationThroughRepositoriesService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update a organizations with found opencatalogi.yml info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findOrganizationThroughRepositoriesService->setStyle($io);

        $this->findOrganizationThroughRepositoriesService->findOrganizationThroughRepositoriesHandler();

        return 0;
    }
}
