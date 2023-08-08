<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FederalizationGetCommand extends Command
{

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:fedaralization:get';

    /**
     * @var FederalizationService
     */
    private FederalizationService $fedService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;


    /**
     * @param FederalizationService  $fedService    The federalization Service
     * @param EntityManagerInterface $entityManager The entity Manager
     */
    public function __construct(
        FederalizationService $fedService,
        EntityManagerInterface $entityManager
    ) {
        $this->fedService    = $fedService;
        $this->entityManager = $entityManager;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command gets al or a single catalogi from the federalized network')
            ->setHelp('This command allows you to run further installation an configuration actions after installing a plugin')
            ->addOption('catalogus', 'c', InputOption::VALUE_OPTIONAL, 'Get a single catalogue by id or name');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->fedService->setStyle($style);

        // Handle the command options
        $catalogusId = $input->getOption('catalogus', false);

        if ($catalogusId === null) {
            $this->fedService->catalogiHandler();
        }

        if ($catalogusId !== null) {
            $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id' => $catalogusId]);
            if ($catalogusObject === null) {
                $style->error('Could not find object entity by id, trying on name');
                $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['name' => $catalogusId]);
            }

            if ($catalogusObject === null) {
                $style->error('Could not find ObjectEntity by id or name '.$catalogusId);

                return Command::FAILURE;
            }

            $this->fedService->readCatalogus($catalogusObject);
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
