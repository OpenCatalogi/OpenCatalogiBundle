<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService
 */
class GithubFindPublicodeCommand extends Command
{
    protected static $defaultName = 'opencatalogi:findGithubRepositoryThroughOrganization:execute';
    private GithubApiService $githubApiService;


    public function __construct(GithubApiService $githubApiService)
    {
        $this->githubApiService = $githubApiService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Find repositories containing publiccode')
            ->setHelp('This command finds repositories on github that contain an publiccode file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findGithubRepositoryThroughOrganizationService->setStyle($io);

        //if(){
//
        //}
        //else{
            $this->githubApiService->handleFindRepositoriesContainingPubliccode();
        //}

        return 0;
    }
}
