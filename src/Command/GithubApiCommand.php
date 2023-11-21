<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the GithubService.
 */
class GithubApiCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:githubapi:repositories';

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param GithubApiService       $githubService   The GithubApiService
     * @param GatewayResourceService $resourceService The Gateway Resource Service
     */
    public function __construct(
        GithubApiService $githubService,
        GatewayResourceService $resourceService
    ) {
        $this->githubService   = $githubService;
        $this->resourceService = $resourceService;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi GithubApiService')
            ->setHelp('This command allows you to get all repositories or one repository with an opencatalogi and/or publiccode file from https://api.github.com/search/code')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Get a single repository by id');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $githubApiAction = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.githubApi.action.json', 'open-catalogi/open-catalogi-bundle');
        $configuration   = $githubApiAction->getConfiguration();

        // Handle the command optiosn
        $repositoryId = $input->getOption('repository', false);

        if ($repositoryId === null) {
            if ($this->githubService->findGithubRepositories([], $configuration) === false) {
                return Command::FAILURE;
            }
        }

        if ($repositoryId !== null
            && $this->githubService->findGithubRepositories([], $configuration, $repositoryId) === null
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
