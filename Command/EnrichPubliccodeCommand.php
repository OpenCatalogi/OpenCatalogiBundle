<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the EnrichPubliccodeCommand.
 */
class EnrichPubliccodeCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console").
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:enrichPubliccode:execute';

    /**
     * @var EnrichPubliccodeService
     */
    private EnrichPubliccodeService $publiccodeService;

    /**
     * @param EnrichPubliccodeService $publiccodeService EnrichPubliccodeService
     */
    public function __construct(EnrichPubliccodeService $publiccodeService)
    {
        $this->publiccodeService = $publiccodeService;
        parent::__construct();
    }//end construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Find repositories containing publiccode')
            ->setHelp('This command finds repositories on github that contain an publiccode file')
            ->addOption('repositoryId', 'r', InputOption::VALUE_OPTIONAL, 'Find a organization for a specific repository by id');
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
        $this->publiccodeService->setStyle($style);

        // Handle the command options
        $repositoryId = $input->getOption('repositoryId', false);

        if ($repositoryId === false) {
            $this->publiccodeService->enrichPubliccodeHandler();
        } elseif ($this->publiccodeService->enrichPubliccodeHandler([], [], $repositoryId) === false) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
