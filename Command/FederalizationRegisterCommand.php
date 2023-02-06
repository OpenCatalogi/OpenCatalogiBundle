<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FederalizationRegisterCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console").
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:fedaralization:register';

    /**
     * @var FederalizationService
     */
    private FederalizationService  $federalizationiService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @param FederalizationService  $federalizationiService FederalizationService
     * @param EntityManagerInterface $entityManager          EntityManagerInterface
     */
    public function __construct(FederalizationService $federalizationiService, EntityManagerInterface $entityManager)
    {
        $this->federalizationiService = $federalizationiService;
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
            ->setHelp('This command allows you to run further installation an configuration actions afther installing a plugin')
            ->addOption('catalogus', 'c', InputOption::VALUE_OPTIONAL, 'Get a single catalogue by id or name');
    }//end configure()

    /**
     * @param InputInterface  $input  The style input
     * @param OutputInterface $output The style output
     *
     * @return int The result of this command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //$this->cacheService->setStyle(new SymfonyStyle($input, $output));
        $style = new SymfonyStyle($input, $output);
        $this->federalizationService->setStyle($style);

        // Handle the command optiosn
        $catalogusId = $input->getOption('catalogus', false);

        if (!$catalogusId) {
            $this->federalizationService->catalogiHandler();
        } else {
            $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$catalogusId]);
            if (!$catalogusObject) {
                $io->debug('Could not find object entity by id, trying on name');
                $catalogusObject = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['name'=>$catalogusId]);
            }
            if (!$catalogusObject) {
                $io->error('Could not find object entity by id or name '.$catalogusId);

                return Command::FAILURE;
            }
            $this->federalizationService->readCatalogus($catalogusObject);
        }

        return Command::SUCCESS;
    }//end execute()
}
