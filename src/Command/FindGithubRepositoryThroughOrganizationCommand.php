<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindRepositoryThroughOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindRepositoryThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindRepositoryThroughOrganizationService.
 */
class FindGithubRepositoryThroughOrganizationCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:github:discoverrepository';

    /**
     * @var FindRepositoryThroughOrganizationService
     */
    private FindRepositoryThroughOrganizationService $findGitService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param FindRepositoryThroughOrganizationService $findGitService  find Github Repository Through Organization Service
     * @param GatewayResourceService                   $resourceService The Gateway Resource Service
     */
    public function __construct(
        FindRepositoryThroughOrganizationService $findGitService,
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
            ->setDescription('This command triggers OpenCatalogi FindRepositoryThroughOrganizationService')
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
            if (empty($this->findGitService->findRepositoryThroughOrganizationHandler([], $configuration)) === true) {
                return Command::FAILURE;
            }
        }

        if (empty($this->findGitService->findRepositoryThroughOrganizationHandler([], $configuration, $organizationId)) === true) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
