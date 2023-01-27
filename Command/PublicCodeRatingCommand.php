<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class PublicCodeRatingCommand extends Command
{
    protected static $defaultName = 'opencatalogi:publiccode:rating';
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
            ->setHelp('This command allows you to update a organizations with found opencatalogi.yml info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->findGithubRepositoryThroughOrganizationService->setStyle($io);

        $this->findGithubRepositoryThroughOrganizationService->findGithubRepositoryThroughOrganizationHandler();

        return Command::SUCCES;
    }
}
