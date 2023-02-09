<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\GithubPubliccodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the GithubService.
 */
class GithubApiGetPubliccodeRepositoriesCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console").
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:githubapi:repositories';

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService  $githubPubliccodeService;

    /**
     * @param GithubPubliccodeService $githubPubliccodeService GithubPubliccodeService
     */
    public function __construct(GithubPubliccodeService $githubPubliccodeService)
    {
        $this->githubPubliccodeService = $githubPubliccodeService;
        parent::__construct();
    }//end __construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi GithubPubliccodeService')
            ->setHelp('This command allows you to get all repositories or one repository from https://api.github.com/search/code')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Get a single repository by id');
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
        $this->githubPubliccodeService->setStyle($style);

        $style->comment('GithubApiGetPubliccodeRepositoriesCommand triggered');

        // Handle the command optiosn
        $repositoryId = $input->getOption('repository', false);

        if ($repositoryId === false) {
            if ($this->githubPubliccodeService->getRepositories() === false) {
                return Command::FAILURE;
            }
        } else if ($this->githubPubliccodeService->getRepository($repositoryId) === false) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
