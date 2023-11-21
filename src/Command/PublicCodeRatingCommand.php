<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use CommonGateway\CoreBundle\Service\GatewayResourceService;
use OpenCatalogi\OpenCatalogiBundle\Service\RatingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the RatingService.
 */
class PublicCodeRatingCommand extends Command
{
    // the name of the command (the part after "bin/console")

    /**
     * @var string
     */
    protected static $defaultName = 'opencatalogi:publiccode:rating';

    /**
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var GatewayResourceService 
     */
    private GatewayResourceService $resourceService;


    /**
     * @param RatingService $ratingService The rating service
     * @param GatewayResourceService $resourceService The Gateway Resource Service
     */
    public function __construct(
        RatingService $ratingService,
        GatewayResourceService $resourceService
    ){
        $this->ratingService = $ratingService;
        $this->resourceService = $resourceService;
        parent::__construct();

    }//end __construct()


    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers OpenCatalogi RatingService')
            ->setHelp('This command allows you to update an organizations with found opencatalogi.yml info')
            ->addOption('component', 'c', InputOption::VALUE_OPTIONAL, 'Rate a single component by id');

    }//end configure()


    /**
     * @param InputInterface  $input  The input
     * @param OutputInterface $output The output
     *
     * @throws \Exception
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ratingAction = $this->resourceService->getAction('https://opencatalogi.nl/action/oc.RatingAction.action.json', 'open-catalogi/open-catalogi-bundle');
        $configuration = $ratingAction->getConfiguration();

        // Handle the command options
        $componentId = $input->getOption('component', false);

        if ($componentId === null) {
            if ($this->ratingService->enrichComponentsWithRating([], $configuration) === null) {
                return Command::FAILURE;
            }
        }

        if ($componentId !== null
            && $this->ratingService->enrichComponentsWithRating([], $configuration, $componentId) === null
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class
