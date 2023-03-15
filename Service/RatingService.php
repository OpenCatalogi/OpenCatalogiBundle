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
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager.
     * @param GithubApiService       $githubApiService The github Api Service.
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger     The plugin version of the loger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->resourceService = $resourceService;
        $this->pluginLogger = $pluginLogger;
        $this->configuration = [];
        $this->data = [];
    }//end __construct()

    /**
     * Create Rating for all components.
     *
     * @throws Exception
     *
     * @return array|null The components with rating.
     */
    public function enrichComponentsWithRating(): ?array
    {
        $result = [];

        $ratingEntity = $this->resourceService->getEntity('https://opencatalogi.nl/oc.rating.schema.json');
        $componentEntity = $this->resourceService->getEntity('https://opencatalogi.nl/oc.component.schema.json');

        $this->pluginLogger->debug('Trying to create ratings for all component ObjectEntities.');

        if (is_countable($componentEntity->getObjectEntities()) === true) {
            $this->pluginLogger->debug('Found '.count($componentEntity->getObjectEntities()).' components.');
        }//end if

        if (is_countable($componentEntity->getObjectEntities()) === false) {
            $this->pluginLogger->debug('Found 0 components.');
        }//end if

        foreach ($componentEntity->getObjectEntities() as $component) {
            $result[] = $this->rateComponent($component, $ratingEntity);
        }//end foreach

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
        $ratingEntity = $this->resourceService->getEntity('https://opencatalogi.nl/oc.rating.schema.json');

        $this->pluginLogger->debug('Trying to get component ObjectEntity with id: '.$id.'.');
        $component = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['id'=>$id]);
        if ($component instanceof ObjectEntity === false) {
            $this->pluginLogger->error('No component ObjectEntity found with id: '.$id.'.');

            return null;
        }//end if

        $component = $this->rateComponent($component, $ratingEntity);
        if ($component === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        return $component->toArray();
    }//end enrichComponentWithRating()

    /**
     * Create Rating for a single component when action for this handler is triggered.
     *
     * @param array $data          Data set at the start of the handler.
     * @param array $configuration Configuration of the action.
     *
     * @throws Exception
     *
     * @return array The component with rating.
     */
    public function ratingHandler(array $data, array $configuration): array
    {
        $this->data = $data;
        $this->configuration = $configuration;

        if (array_key_exists('id', $this->data['response'])) {
            $id = $this->data['response']['id'];

            $this->enrichComponentWithRating($id);
        }//end if

        return $this->data;
    }//end ratingHandler()

    /**
     * Rate a component.
     *
     * @param ObjectEntity $component    The component to rate.
     * @param Entity       $ratingEntity The rating entity.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateComponent(ObjectEntity $component, Entity $ratingEntity): ?ObjectEntity
    {
        $ratingComponent = $this->ratingList($component);

        $rating = $component->getValue('rating');

        if ($rating === false) {
            $rating = new ObjectEntity();
            $rating->setEntity($ratingEntity);
        }//end if

        $rating->setValue('rating', $ratingComponent['rating']);
        $rating->setValue('maxRating', $ratingComponent['maxRating']);
        $rating->setValue('results', $ratingComponent['results']);
        $this->entityManager->persist($rating);

        $component->setValue('rating', $rating);
        $this->entityManager->persist($component);

        $this->pluginLogger->debug("Created rating ({$rating->getId()->toString()}) for component ObjectEntity with id: {$component->getId()->toString()}");

        return $component;
    }//end rateComponent()

    /**
     * Rates the name of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateName(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('name') !== null) {
            $ratingArray['results'][] = 'The name: '.$component->getValue('name').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the name because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the url of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateUrl(ObjectEntity $component, array $ratingArray): array
    {
        if ($repository = $component->getValue('url')) {
            $url = $repository->getValue('url');
            if (!empty($url)) {
                $ratingArray['results'][] = 'The url: '.$url.' rated';
                $ratingArray['rating']++;

                $domain = \Safe\parse_url($url, PHP_URL_HOST);
                if ($domain !== 'github.com') {
                    $ratingArray['results'][] = 'Cannot rate the repository because it is not a valid github repository';
                } elseif ($this->githubApiService->checkPublicRepository($url)) {
                    $ratingArray['results'][] = 'Rated the repository because it is public';
                    $ratingArray['rating']++;
                } else {
                    $ratingArray['results'][] = 'Cannot rate the repository because it is private (or url is invalid)';
                }

                $ratingArray['maxRating']++;
            } elseif ($url === null) {
                $ratingArray['results'][] = 'Cannot rate the url because it is not set';
            } else {
                $ratingArray['results'][] = 'Cannot rate the repository because url is empty';
            }

            $ratingArray['maxRating']++;
        }//end if

        $ratingArray['maxRating'] = $ratingArray['maxRating'] + 2;

        return $ratingArray;
    }

    /**
     * Rates the landing url of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLandingUrl(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('landingURL') !== null) {
            $ratingArray['results'][] = 'The landingURL: '.$component->getValue('landingURL').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the landingURL because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the software version of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateSoftwareVersion(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('softwareVersion') !== null) {
            $ratingArray['results'][] = 'The softwareVersion: '.$component->getValue('softwareVersion').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the softwareVersion because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the release date of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateReleaseDate(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('releaseDate') !== null) {
            $ratingArray['results'][] = 'The releaseDate: '.$component->getValue('releaseDate').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the releaseDate because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the logo of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLogo(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('logo') !== null) {
            $ratingArray['results'][] = 'The logo: '.$component->getValue('logo').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the logo because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the roadmap of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateRoadmap(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('roadmap') !== null) {
            $ratingArray['results'][] = 'The roadmap: '.$component->getValue('roadmap').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the roadmap because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the development status of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateDevelopmentStatus(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('developmentStatus') !== null) {
            $ratingArray['results'][] = 'The developmentStatus: '.$component->getValue('developmentStatus').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the developmentStatus because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the software type of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateSoftwareType(ObjectEntity $component, array $ratingArray): array
    {
        if ($component->getValue('softwareType') !== null) {
            $ratingArray['results'][] = 'The softwareType: '.$component->getValue('softwareType').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the softwareType because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the platforms of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function ratePlatforms(ObjectEntity $component, array $ratingArray): array
    {
        if (count($component->getValue('platforms')) > 0) {
            $ratingArray['results'][] = 'The platforms are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the platforms because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the categories of the component.
     *
     * @param ObjectEntity $component The component to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateCategories(ObjectEntity $component, array $ratingArray): array
    {
        if (count($component->getValue('categories')) > 0) {
            $ratingArray['results'][] = 'The categories are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the categories because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the localised name of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLocalisedName(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('localisedName') !== null) {
            $ratingArray['results'][] = 'The localisedName: '.$descriptionObject->getValue('localisedName').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the localisedName because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the short description of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateShortDescription(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('shortDescription') !== null) {
            $ratingArray['results'][] = 'The shortDescription: '.$descriptionObject->getValue('shortDescription').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the shortDescription because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the long description of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLongDescription(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('longDescription') !== null) {
            $ratingArray['results'][] = 'The longDescription: '.$descriptionObject->getValue('longDescription').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the longDescription because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the api documentation of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateApiDocumentation(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if ($descriptionObject->getValue('apiDocumentation') !== null) {
            $ratingArray['results'][] = 'The apiDocumentation: '.$descriptionObject->getValue('apiDocumentation').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the apiDocumentation because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the features of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateFeatures(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('features')) > 0) {
            $ratingArray['results'][] = 'The features are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the features because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the screenshots of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateScreenshots(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('screenshots')) > 0) {
            $ratingArray['results'][] = 'The screenshots are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the screenshots because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the screenshots of the description.
     *
     * @param ObjectEntity $descriptionObject The description to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateVideos(ObjectEntity $descriptionObject, array $ratingArray): array
    {
        if (count($descriptionObject->getValue('videos')) > 0) {
            $ratingArray['results'][] = 'The videos are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the videos because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the license of the legal object.
     *
     * @param ObjectEntity $legalObject The legal object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateLicense(ObjectEntity $legalObject, array $ratingArray): array
    {
        if ($legalObject->getValue('license') !== null) {
            $ratingArray['results'][] = 'The license: '.$legalObject->getValue('license').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the license because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the copy right owner.
     *
     * @param ObjectEntity $mainCopyrightOwnerObject The main copyright owner to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateCopyOwner(ObjectEntity $mainCopyrightOwnerObject, array $ratingArray): array
    {
        if ($mainCopyrightOwnerObject->getValue('mainCopyrightOwner') !== null) {
            $ratingArray['results'][] = 'The mainCopyrightOwner: '.$mainCopyrightOwnerObject->getValue('name').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the mainCopyrightOwner because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the copy right owner.
     *
     * @param ObjectEntity $mainCopyrightOwnerObject The main copyright owner to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateRepoOwner(ObjectEntity $mainCopyrightOwnerObject, array $ratingArray): array
    {
        if ($repoOwnerObject->getValue('repoOwner') !== null) {
            $ratingArray['results'][] = 'The repoOwner is rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the repoOwner because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the authors file of the legal object.
     *
     * @param ObjectEntity $legalObject The legal object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateAuthorsFile(ObjectEntity $legalObject, array $ratingArray): array
    {
        if ($legalObject->getValue('authorsFile') !== null) {
            $ratingArray['results'][] = 'The authorsFile: '.$legalObject->getValue('authorsFile').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the authorsFile because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateType(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if ($maintenanceObject->getValue('type') !== null) {
            $ratingArray['results'][] = 'The type: '.$maintenanceObject->getValue('type').' rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the type because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateContractors(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if (count($maintenanceObject->getValue('contractors')) > 0) {
            $ratingArray['results'][] = 'The contractors are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the contractors because it is not set';
        }
        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates the type of the maintenace object.
     *
     * @param ObjectEntity $maintenanceObject The maintenance object to rate.
     * @param array $ratingArray The rating array.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function rateContacts(ObjectEntity $maintenanceObject, array $ratingArray): array
    {
        if (count($maintenanceObject->getValue('contacts')) > 0) {
            $ratingArray['results'][] = 'The contacts are rated';
            $ratingArray['rating']++;
        } else {
            $ratingArray['results'][] = 'Cannot rate the contacts because it is not set';
        }

        $ratingArray['maxRating']++;

        return $ratingArray;
    }

    /**
     * Rates a component.
     *
     * @param ObjectEntity $component The component to rate.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function ratingList(ObjectEntity $component): ?array
    {
        $ratingArray = ['rating' => 0, 'maxRating' => 0, 'results' => []];

        $ratingArray = $this->rateName($component, $ratingArray);
        $ratingArray = $this->rateUrl($component, $ratingArray);
        $ratingArray = $this->rateLandingUrl($component, $ratingArray);
        $ratingArray = $this->rateSoftwareVersion($component, $ratingArray);
        $ratingArray = $this->rateReleaseDate($component, $ratingArray);
        $ratingArray = $this->rateLogo($component, $ratingArray);
        $ratingArray = $this->rateRoadmap($component, $ratingArray);
        $ratingArray = $this->rateDevelopmentStatus($component, $ratingArray);
        $ratingArray = $this->rateSoftwareType($component, $ratingArray);
        $ratingArray = $this->ratePlatforms($component, $ratingArray);
        $ratingArray = $this->rateCategories($component, $ratingArray);

        if ($descriptionObject = $component->getValue('description')) {
            $ratingArray = $this->rateLocalisedName($descriptionObject, $ratingArray);
            $ratingArray = $this->rateShortDescription($descriptionObject, $ratingArray);
            $ratingArray = $this->rateLongDescription($descriptionObject, $ratingArray);
            $ratingArray = $this->rateApiDocumentation($descriptionObject, $ratingArray);
            $ratingArray = $this->rateFeatures($descriptionObject, $ratingArray);
            $ratingArray = $this->rateScreenshots($descriptionObject, $ratingArray);
            $ratingArray = $this->rateVideos($descriptionObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the description object because it is not set';
            $ratingArray['maxRating'] = $ratingArray['maxRating'] + 7;
        }

        if ($legalObject = $component->getValue('legal')) {
            $ratingArray = $this->rateLicense($legalObject, $ratingArray);

            if ($mainCopyrightOwnerObject = $legalObject->getValue('mainCopyrightOwner')) {
                $ratingArray = $this->rateCopyOwner($mainCopyrightOwnerObject, $ratingArray);
            }//end if

            if ($repoOwnerObject = $legalObject->getValue('repoOwner')) {
                $ratingArray = $this->rateRepoOwner($repoOwnerObject, $ratingArray);
            }//end if

            $ratingArray = $this->rateAuthorsFile($legalObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the legal object because it is not set';
            $ratingArray['maxRating'] = $ratingArray['maxRating'] + 2;
        }

        if ($maintenanceObject = $component->getValue('maintenance')) {
            $ratingArray = $this->rateType($maintenanceObject, $ratingArray);
            $ratingArray = $this->rateContractors($maintenanceObject, $ratingArray);
            $ratingArray = $this->rateContacts($maintenanceObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the maintenance object because it is not set';
            $ratingArray['maxRating'] = $ratingArray['maxRating'] + 3;
        }

        return $ratingArray;
    }//end ratingList()
}//end class
