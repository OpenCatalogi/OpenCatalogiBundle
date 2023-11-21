<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
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
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:github:discoverrepository';

    /**
     * @var FindGithubRepositoryThroughOrganizationService
     */
    private FindGithubRepositoryThroughOrganizationService $findGitService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param FindGithubRepositoryThroughOrganizationService $findGitService  find Github Repository Through Organization Service
     * @param GatewayResourceService                         $resourceService The Gateway Resource Service
     */
    public function __construct(
        FindGithubRepositoryThroughOrganizationService $findGitService,
        GatewayResourceService $resourceService
    ) {
        $this->findGitService  = $findGitService;
        $this->resourceService = $resourceService;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi FindGithubRepositoryThroughOrganizationService')
            ->setHelp('This command allows you to update create owned repositories from organisation')
            ->addOption('organizationId', 'o', InputOption::VALUE_OPTIONAL, 'Find owned repositories for a specific organisation by id');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $githubRepoAction = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.FindGithubRepositoryThroughOrganizationAction.action.json', 'open-catalogi/open-catalogi-bundle');
        $configuration    = $githubRepoAction->getConfiguration();

        // Handle the command options
        $organizationId = $input->getOption('organizationId', false);

        if ($organizationId === null) {
            if (empty($this->findGitService->findGithubRepositoryThroughOrganizationHandler([], $configuration)) === true) {
                return Command::FAILURE;
            }
        }

        if (empty($this->findGitService->findGithubRepositoryThroughOrganizationHandler([], $configuration, $organizationId)) === true) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
