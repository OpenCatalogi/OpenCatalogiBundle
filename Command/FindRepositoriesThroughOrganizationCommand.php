<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindOrganizationThroughRepositoriesService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindRepositoriesThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindOrganizationThroughRepositoriesService.
 */
class FindRepositoriesThroughOrganizationCommand extends Command
{
    protected static $defaultName = 'opencatalogi:findRepositoriesThroughOrganization:execute';
    private FindRepositoriesThroughOrganizationService  $findRepositoriesThroughOrganizationService;

    public function __construct(FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService)
    {
        $this->findRepositoriesThroughOrganizationService = $findRepositoriesThroughOrganizationService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindRepositoriesThroughOrganizationCommand')
            ->setHelp('This command allows you to update create owned repositories from orgasation')
            ->addOption('organisationId', 'o', InputOption::VALUE_OPTIONAL, 'Find owned repositories for a specific organisation by id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findRepositoriesThroughOrganizationService->setStyle($io);

        // Handle the command options
        $organisationId = $input->getOption('organisationId', false);

        if (!$organisationId) {
            if (!$this->findRepositoriesThroughOrganizationService->findRepositoriesThroughOrganisationHandler()) {
                return Command::FAILURE;
            }
        } elseif (!$this->findRepositoriesThroughOrganizationService->findRepositoriesThroughOrganisationHandler([], [], $organisationId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
