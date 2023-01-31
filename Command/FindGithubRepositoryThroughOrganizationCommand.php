<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class FindGithubRepositoryThroughOrganizationCommand extends Command
{
    protected static $defaultName = 'opencatalogi:github:discoverrepository';
    private FindGithubRepositoryThroughOrganizationService  $findGithubRepositoryThroughOrganizationService;

    public function __construct(FindGithubRepositoryThroughOrganizationService $findGithubRepositoryThroughOrganizationService)
    {
        $this->findGithubRepositoryThroughOrganizationService = $findGithubRepositoryThroughOrganizationService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update create owned repositories from orgasation')
            ->addOption('organisationId', 'o', InputOption::VALUE_OPTIONAL, 'Find owned repositories for a specific organisation by id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findGithubRepositoryThroughOrganizationService->setStyle($io);

        // Handle the command options
        $organisationId = $input->getOption('organisationId', false);

        if (!$organisationId) {
            if (!$this->findGithubRepositoryThroughOrganizationService->findGithubRepositoryThroughOrganizationHandler()) {
                return Command::FAILURE;
            }
        } elseif (!$this->findGithubRepositoryThroughOrganizationService->findGithubRepositoryThroughOrganizationHandler([], [], $organisationId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
