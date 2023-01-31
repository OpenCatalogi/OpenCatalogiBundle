<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;

class RatingService
{
    private EntityManagerInterface $entityManager;
    private GithubApiService $githubApiService;
    private array $configuration;
    private array $data;
    private ?Entity $componentEntity;
    private ?Entity $ratingEntity;
    private SymfonyStyle $io;

    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService
    ) {
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->configuration = [];
        $this->data = [];
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;
        $this->githubApiService->setStyle($io);

        return $this;
    }

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        if (!$this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.component.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');
        }

        return $this->componentEntity;
    }

    /**
     * Get the rating entity.
     *
     * @return ?Entity
     */
    public function getRatingEntity(): ?Entity
    {
        if (!$this->ratingEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.rating.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.rating.schema.json');
        }

        return $this->ratingEntity;
    }

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
    }

    /**
     * Create Rating for a single component.
     *
     * @param string $id
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
    }

    /**
     * @param ObjectEntity $component
     * @param Entity       $ratingEntity
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
    }

    /**
     * Rates a component.
     *
     * @param ObjectEntity $component
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

        // todo: use GithubApiService functions to check if url->source is github and only than continue
        if ($repository = $component->getValue('url')) {
            if ($repository->getValue('url') !== null) {
                $description[] = 'The url: '.$repository->getValue('url').' rated';
                $rating++;

                if (empty($repository->getValue('url'))) {
                    $description[] = 'Cannot rate the repository because url is empty';
                } elseif ($this->githubApiService->checkPublicRepository($repository->getValue('url'))) {
                    $description[] = 'Rated the repository because it is public';
                    $rating++;
                } else {
                    $description[] = 'Cannot rate the repository because it is private';
                }
                $maxRating++;
            } else {
                $description[] = 'Cannot rate the url because it is not set';
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
    }
}
