<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the DeveloperOverheidService.
 */
class DeveloperOverheidGetComponentsCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console").
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:developeroverheid:components';

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService  $developerOverheidService;

    /**
     * @param DeveloperOverheidService $developerOverheidService DeveloperOverheidService
     */
    public function __construct(DeveloperOverheidService $developerOverheidService)
    {
        $this->developerOverheidService = $developerOverheidService;
        parent::__construct();
    }//end construct()

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi DeveloperOverheidService')
            ->setHelp('This command allows you to get all components or one component from developer.overheid.nl/apis')
            ->addOption('component', 'c', InputOption::VALUE_OPTIONAL, 'Get a single component by id');
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
        $this->developerOverheidService->setStyle($style);

        // Handle the command options
        $componentId = $input->getOption('component', false);

        if ($componentId === false) {
            if ($this->developerOverheidService->getComponents() === false) {
                return Command::FAILURE;
            }
        } elseif ($this->developerOverheidService->getComponent($componentId) === false) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
