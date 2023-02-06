<?php

namespace OpenCatalogi\OpenCatalogiBundle\Command;

use OpenCatalogi\OpenCatalogiBundle\Service\RatingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the RatingService.
 *
 * ```cli $ opencatalogi:publiccode:rating```
 */
class PublicCodeRatingCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console")
     *
     * @var string
     */
    protected static $defaultName = 'opencatalogi:publiccode:rating';

    /**
     * @var RatingService
     */
    private RatingService  $ratingService;

    /**
     * @param RatingService $ratingService RatingService
     */
    public function __construct(RatingService $ratingService)
    {
        $this->ratingService = $ratingService;
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
     * @param InputInterface $input The style input
     * @param OutputInterface $output  The style output
     * @return int The result of this command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->ratingService->setStyle($style);

        // Handle the command options
        $componentId = $input->getOption('component', false);

        if (!$componentId) {
            if (!$this->ratingService->enrichComponentsWithRating()) {
                return Command::FAILURE;
            }
        } elseif (!$this->ratingService->enrichComponentWithRating($componentId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
