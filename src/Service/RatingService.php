<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

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
     * @var RatingListService
     */
    private RatingListService $ratingListService;

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
     * @param EntityManagerInterface $entityManager     The Entity Manager.
     * @param GithubApiService       $githubApiService  The github Api Service.
     * @param RatingListService      $ratingListService The Rating List Service.
     * @param GatewayResourceService $resourceService   The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger      The plugin version of the logger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        RatingListService $ratingListService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager     = $entityManager;
        $this->githubApiService  = $githubApiService;
        $this->ratingListService = $ratingListService;
        $this->resourceService   = $resourceService;
        $this->pluginLogger      = $pluginLogger;
        $this->configuration     = [];
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

        $ratingEntity    = $this->resourceService->getSchema('https://opencatalogi.nl/oc.rating.schema.json', 'open-catalogi/open-catalogi-bundle');
        $componentEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->info('Trying to create ratings for all component ObjectEntities.');

        if (is_countable($componentEntity->getObjectEntities()) === true) {
            $this->pluginLogger->info('Found '.count($componentEntity->getObjectEntities()).' components.');
        }//end if

        if (is_countable($componentEntity->getObjectEntities()) === false) {
            $this->pluginLogger->info('Found 0 components.');
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
     * @param string $componentId The id of the component to enrich
     *
     * @throws Exception
     *
     * @return array|null dataset at the end of the handler
     */
    public function enrichComponentWithRating(string $componentId): ?array
    {
        $ratingEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.rating.schema.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->debug('Trying to get component ObjectEntity with id: '.$componentId.'.');
        $component = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['id' => $componentId]);
        if ($component instanceof ObjectEntity === false) {
            $this->pluginLogger->error('No component ObjectEntity found with id: '.$componentId.'.');

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
        $this->data          = $data;
        $this->configuration = $configuration;

        if (array_key_exists('id', $this->data['response']) === true) {
            $componentId = $this->data['response']['id'];

            $this->enrichComponentWithRating($componentId);
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

        if ($rating === null) {
            $rating = new ObjectEntity();
            $rating->setEntity($ratingEntity);
        }//end if

        $rating->setValue('rating', $ratingComponent['rating']);
        $rating->setValue('maxRating', $ratingComponent['maxRating']);
        $rating->setValue('results', $ratingComponent['results']);
        $this->entityManager->persist($rating);

        $component->setValue('rating', $rating);
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        $this->pluginLogger->debug("Created rating ({$rating->getId()->toString()}) for component ObjectEntity with id: {$component->getId()->toString()}");

        return $component;

    }//end rateComponent()


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
        $ratingArray = [
            'rating'    => 0,
            'maxRating' => 0,
            'results'   => [],
        ];

        $ratingArray = $this->ratingListService->rateName($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateUrl($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateLandingUrl($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateSoftwareVersion($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateReleaseDate($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateLogo($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateRoadmap($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateDevelopmentStatus($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateSoftwareType($component, $ratingArray);
        $ratingArray = $this->ratingListService->ratePlatforms($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateCategories($component, $ratingArray);

        if ($descriptionObject = $component->getValue('description') === null) {
            $ratingArray = $this->ratingListService->rateLocalisedName($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateShortDescription($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateLongDescription($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateApiDocumentation($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateFeatures($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateScreenshots($descriptionObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateVideos($descriptionObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the description object because it is not set';
            $ratingArray['maxRating'] = ($ratingArray['maxRating'] + 7);
        }

        if ($legalObject = $component->getValue('legal') === null) {
            $ratingArray = $this->ratingListService->rateLicense($legalObject, $ratingArray);

            if ($mainOwnerObject = $legalObject->getValue('mainCopyrightOwner') === null) {
                $ratingArray = $this->ratingListService->rateCopyOwner($mainOwnerObject, $ratingArray);
            }//end if

            if ($repoOwnerObject = $legalObject->getValue('repoOwner') === null) {
                $ratingArray = $this->ratingListService->rateRepoOwner($repoOwnerObject, $ratingArray);
            }//end if

            $ratingArray = $this->ratingListService->rateAuthorsFile($legalObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the legal object because it is not set';
            $ratingArray['maxRating'] = ($ratingArray['maxRating'] + 2);
        }

        if ($maintenanceObject = $component->getValue('maintenance') === null) {
            $ratingArray = $this->ratingListService->rateType($maintenanceObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateContractors($maintenanceObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateContacts($maintenanceObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the maintenance object because it is not set';
            $ratingArray['maxRating'] = ($ratingArray['maxRating'] + 3);
        }

        return $ratingArray;

    }//end ratingList()


}//end class
