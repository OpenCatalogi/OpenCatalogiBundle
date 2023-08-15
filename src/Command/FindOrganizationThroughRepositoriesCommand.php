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
    private FindOrganizationThroughRepositoriesService $findOrgRepService;


    /**
     * @param FindOrganizationThroughRepositoriesService $findOrgRepService find Organization Through Repositories Service
     */
    public function __construct(FindOrganizationThroughRepositoriesService $findOrgRepService)
    {
        $this->findOrgRepService = $findOrgRepService;
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
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = [
            'githubSource'        => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
            'repositorySchema'    => 'https://opencatalogi.nl/oc.repository.schema.json',
            'organisationSchema'  => 'https://opencatalogi.nl/oc.organisation.schema.json',
            'componentSchema'     => 'https://opencatalogi.nl/oc.component.schema.json',
            'organisationMapping' => 'https://api.github.com/oc.githubOrganisation.mapping.json',
            'repositoryMapping'   => 'https://api.github.com/oc.githubRepository.mapping.json',
        ];

        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if ($repositoryId === null) {
            if (empty($this->findOrgRepService->findOrganizationThroughRepositoriesHandler([], $configuration)) === true) {
                return Command::FAILURE;
            }
        }

        if ($repositoryId !== null
            && empty($this->findOrgRepService->findOrganizationThroughRepositoriesHandler([], $configuration, $repositoryId)) === true
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
