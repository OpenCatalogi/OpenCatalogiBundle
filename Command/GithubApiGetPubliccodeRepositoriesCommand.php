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
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'opencatalogi:githubapi:repositories';
    private GithubPubliccodeService  $githubPubliccodeService;

    public function __construct(GithubPubliccodeService $githubPubliccodeService)
    {
        $this->githubPubliccodeService = $githubPubliccodeService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi GithubPubliccodeService')
            ->setHelp('This command allows you to get all repositories or one repository from https://api.github.com/search/code')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'Get a single repository by id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->githubPubliccodeService->setStyle($io);

        // Handle the command optiosn
        $repositoryId = $input->getOption('repository', false);

        if (!$repositoryId) {
            $this->githubPubliccodeService->getRepositories();
        } else {
            $this->githubPubliccodeService->getRepository($repositoryId);
        }

        return Command::SUCCES;
    }
}
