<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RatingService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @var Entity|null
     */
    private ?Entity $ratingEntity;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager
     * @param GithubApiService       $githubApiService The githubApiService
     * @param LoggerInterface        $pluginLogger     The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->logger = $pluginLogger;

        $this->configuration = [];
        $this->data = [];
    }//end __construct()

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io The symfony style element
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;
        $this->githubApiService->setStyle($io);

        return $this;
    }//end setStyle()

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        if (!$this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.component.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');

            return null;
        }

        return $this->componentEntity;
    }//end getComponentEntity()

    /**
     * Get the rating entity.
     *
     * @return ?Entity
     */
    public function getRatingEntity(): ?Entity
    {
        if (!$this->ratingEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.rating.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.rating.schema.json');

            return null;
        }

        return $this->ratingEntity;
    }//end getRatingEntity()

    /**
     * Create Rating for all components.
     *
     * @throws Exception
     *
     * @return array|null
     */
    public function enrichComponentsWithRating(): ?array
    {
        $result = [];

        if (!$ratingEntity = $this->getRatingEntity()) {
            isset($this->io) && $this->io->error('No RatingEntity found when trying to create ratings for all component ObjectEntities');

            return null;
        }

        if (!$componentEntity = $this->getComponentEntity()) {
            isset($this->io) && $this->io->error('No ComponentEntity found when trying to create ratings for all component ObjectEntities');

            return null;
        }

        isset($this->io) && $this->io->comment('Trying to create ratings for all component ObjectEntities');

        isset($this->io) && is_countable($componentEntity->getObjectEntities()) ? $this->io->success('Found '.count($componentEntity->getObjectEntities()).' components') : $this->io->success('Found 0 components');
        foreach ($componentEntity->getObjectEntities() as $component) {
            $result[] = $this->rateComponent($component, $ratingEntity);
        }

        $this->entityManager->flush();

        return $result;
    }//end enrichComponentsWithRating()

    /**
     * Create Rating for a single component.
     *
     * @param string $id The id of the component to enrich
     *
     * @throws Exception
     *
     * @return array|null dataset at the end of the handler
     */
    public function enrichComponentWithRating(string $id): ?array
    {
        if (!$ratingEntity = $this->getRatingEntity()) {
            isset($this->io) && $this->io->error('No RatingEntity found when trying to create a Rating for Component ObjectEntity with id: '.$id);

            return null;
        }

        isset($this->io) && $this->io->comment('Trying to get component ObjectEntity with id: '.$id);
        $component = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$id]);
        if (!$component instanceof ObjectEntity) {
            isset($this->io) && $this->io->error('No component ObjectEntity found with id: '.$id);

            return null;
        }

        $component = $this->rateComponent($component, $ratingEntity);
        if ($component === null) {
            return null;
        }

        $this->entityManager->flush();

        return $component->toArray();
    }//end enrichComponentWithRating()

    /**
     * Create Rating for a single component when action for this handler is triggered.
     *
     * @param array $data          data set at the start of the handler
     * @param array $configuration configuration of the action
     *
     * @throws Exception
     *
     * @return array
     */
    public function ratingHandler(array $data, array $configuration): array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        if (array_key_exists('id', $this->data['response'])) {
            $id = $this->data['response']['id'];

            $this->enrichComponentWithRating($id);
        }

        return $this->data;
    }//end ratingHandler()

    /**
     * Rate a component.
     *
     * @param ObjectEntity $component    The component to rate
     * @param Entity       $ratingEntity The rating entity
     *
     * @throws Exception
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function rateComponent(ObjectEntity $component, Entity $ratingEntity): ?ObjectEntity
    {
        $ratingComponent = $this->ratingList($component);

        if (!$component->getValue('rating')) {
            $rating = new ObjectEntity();
            $rating->setEntity($ratingEntity);
        } else {
            $rating = $component->getValue('rating');
        }

        $rating->setValue('rating', $ratingComponent['rating']);
        $rating->setValue('maxRating', $ratingComponent['maxRating']);
        $rating->setValue('results', $ratingComponent['results']);
        $this->entityManager->persist($rating);

        $component->setValue('rating', $rating);
        $this->entityManager->persist($component);

        isset($this->io) && $this->io->success("Created rating ({$rating->getId()->toString()}) for component ObjectEntity with id: {$component->getId()->toString()}");

        return $component;
    }//end rateComponent()

    /**
     * Rates a component.
     *
     * @param ObjectEntity $component The component to rate
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null dataset at the end of the handler
     */
    public function ratingList(ObjectEntity $component): ?array
    {
        $rating = 0;
        $maxRating = 0;
        $description = [];

        if ($component->getValue('name') !== null) {
            $description[] = 'The name: '.$component->getValue('name').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the name because it is not set';
        }

        $maxRating++;

        if ($repository = $component->getValue('url')) {
            $url = $repository->getValue('url');
            if (!empty($url)) {
                $description[] = 'The url: '.$url.' rated';
                $rating++;

                $domain = \Safe\parse_url($url, PHP_URL_HOST);
                if ($domain !== 'github.com') {
                    $description[] = 'Cannot rate the repository because it is not a valid github repository';
                } elseif ($this->githubApiService->checkPublicRepository($url)) {
                    $description[] = 'Rated the repository because it is public';
                    $rating++;
                } else {
                    $description[] = 'Cannot rate the repository because it is private (or url is invalid)';
                }
                $maxRating++;
            } elseif ($url === null) {
                $description[] = 'Cannot rate the url because it is not set';
            } else {
                $description[] = 'Cannot rate the repository because url is empty';
            }
            $maxRating++;
        }
        $maxRating = $maxRating + 2;

        if ($component->getValue('landingURL') !== null) {
            $description[] = 'The landingURL: '.$component->getValue('landingURL').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the landingURL because it is not set';
        }
        $maxRating++;

        if ($component->getValue('softwareVersion') !== null) {
            $description[] = 'The softwareVersion: '.$component->getValue('softwareVersion').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the softwareVersion because it is not set';
        }
        $maxRating++;

        if ($component->getValue('releaseDate') !== null) {
            $description[] = 'The releaseDate: '.$component->getValue('releaseDate').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the releaseDate because it is not set';
        }
        $maxRating++;

        if ($component->getValue('logo') !== null) {
            $description[] = 'The logo: '.$component->getValue('logo').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the logo because it is not set';
        }
        $maxRating++;

        if ($component->getValue('roadmap') !== null) {
            $description[] = 'The roadmap: '.$component->getValue('roadmap').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the roadmap because it is not set';
        }
        $maxRating++;

        if ($component->getValue('developmentStatus') !== null) {
            $description[] = 'The developmentStatus: '.$component->getValue('developmentStatus').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the developmentStatus because it is not set';
        }
        $maxRating++;

        if ($component->getValue('softwareType') !== null) {
            $description[] = 'The softwareType: '.$component->getValue('softwareType').' rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the softwareType because it is not set';
        }
        $maxRating++;

        if (count($component->getValue('platforms')) > 0) {
            $description[] = 'The platforms are rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the platforms because it is not set';
        }
        $maxRating++;

        if (count($component->getValue('categories')) > 0) {
            $description[] = 'The categories are rated';
            $rating++;
        } else {
            $description[] = 'Cannot rate the categories because it is not set';
        }
        $maxRating++;

        if ($descriptionObject = $component->getValue('description')) {
            if ($descriptionObject->getValue('localisedName') !== null) {
                $description[] = 'The localisedName: '.$descriptionObject->getValue('localisedName').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the localisedName because it is not set';
            }
            $maxRating++;

            if ($descriptionObject->getValue('shortDescription') !== null) {
                $description[] = 'The shortDescription: '.$descriptionObject->getValue('shortDescription').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the shortDescription because it is not set';
            }
            $maxRating++;

            if ($descriptionObject->getValue('longDescription') !== null) {
                $description[] = 'The longDescription: '.$descriptionObject->getValue('longDescription').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the longDescription because it is not set';
            }
            $maxRating++;

            if ($descriptionObject->getValue('apiDocumentation') !== null) {
                $description[] = 'The apiDocumentation: '.$descriptionObject->getValue('apiDocumentation').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the apiDocumentation because it is not set';
            }
            $maxRating++;

            if (count($descriptionObject->getValue('features')) > 0) {
                $description[] = 'The features are rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the features because it is not set';
            }
            $maxRating++;

            if (count($descriptionObject->getValue('screenshots')) > 0) {
                $description[] = 'The screenshots are rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the screenshots because it is not set';
            }
            $maxRating++;

            if (count($descriptionObject->getValue('videos')) > 0) {
                $description[] = 'The videos are rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the videos because it is not set';
            }
            $maxRating++;
        } else {
            $description[] = 'Cannot rate the description object because it is not set';
            $maxRating = $maxRating + 7;
        }

        if ($legalObject = $component->getValue('legal')) {
            if ($legalObject->getValue('license') !== null) {
                $description[] = 'The license: '.$legalObject->getValue('license').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the license because it is not set';
            }
            $maxRating++;

            if ($mainCopyrightOwnerObject = $legalObject->getValue('mainCopyrightOwner')) {
                if ($mainCopyrightOwnerObject->getValue('mainCopyrightOwner') !== null) {
                    $description[] = 'The mainCopyrightOwner: '.$mainCopyrightOwnerObject->getValue('name').' rated';
                    $rating++;
                } else {
                    $description[] = 'Cannot rate the mainCopyrightOwner because it is not set';
                }
                $maxRating++;
            }

            if ($repoOwnerObject = $legalObject->getValue('repoOwner')) {
                if ($repoOwnerObject->getValue('repoOwner') !== null) {
                    $description[] = 'The repoOwner is rated';
                    $rating++;
                } else {
                    $description[] = 'Cannot rate the repoOwner because it is not set';
                }
                $maxRating++;
            }

            if ($legalObject->getValue('authorsFile') !== null) {
                $description[] = 'The authorsFile: '.$legalObject->getValue('authorsFile').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the authorsFile because it is not set';
            }
            $maxRating++;
        } else {
            $description[] = 'Cannot rate the legal object because it is not set';
            $maxRating = $maxRating + 2;
        }

        if ($maintenanceObject = $component->getValue('maintenance')) {
            if ($maintenanceObject->getValue('type') !== null) {
                $description[] = 'The type: '.$maintenanceObject->getValue('type').' rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the type because it is not set';
            }
            $maxRating++;

            if (count($maintenanceObject->getValue('contractors')) > 0) {
                $description[] = 'The contractors are rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the contractors because it is not set';
            }
            $maxRating++;

            if (count($maintenanceObject->getValue('contacts')) > 0) {
                $description[] = 'The contacts are rated';
                $rating++;
            } else {
                $description[] = 'Cannot rate the contacts because it is not set';
            }
            $maxRating++;
        } else {
            $description[] = 'Cannot rate the maintenance object because it is not set';
            $maxRating = $maxRating + 3;
        }

        return [
            'rating'    => $rating,
            'maxRating' => $maxRating,
            'results'   => $description,
        ];
    }//end ratingList()
}//end class
