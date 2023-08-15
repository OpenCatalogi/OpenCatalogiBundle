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
     * @param RatingListService      $ratingListService The Rating List Service.
     * @param GatewayResourceService $resourceService   The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger      The plugin version of the logger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        RatingListService $ratingListService,
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager     = $entityManager;
        $this->ratingListService = $ratingListService;
        $this->resourceService   = $resourceService;
        $this->pluginLogger      = $pluginLogger;
        $this->configuration     = [];
        $this->data              = [];

    }//end __construct()


    /**
     * Create Rating for all components.
     *
     * @param ?array      $data          data set at the start of the handler (not needed here)
     * @param ?array      $configuration configuration of the action          (not needed here)
     * @param string|null $componentId   optional component id.
     *
     * @throws Exception
     *
     * @return array|null The components with rating.
     */
    public function enrichComponentsWithRating(?array $data=[], ?array $configuration=[], ?string $componentId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        $result = [];

        $componentSchema = $this->resourceService->getSchema($this->configuration['componentSchema'], 'open-catalogi/open-catalogi-bundle');

        if ($componentId === null) {
            $this->pluginLogger->info('Trying to create ratings for all component ObjectEntities.');

            if (is_countable($componentSchema->getObjectEntities()) === false) {
                $this->pluginLogger->info('Found 0 components.');

                return $result;
            }//end if

            $this->pluginLogger->info('Found '.count($componentSchema->getObjectEntities()).' components.');
            foreach ($componentSchema->getObjectEntities() as $component) {
                $result[] = $this->rateComponent($component);
            }//end foreach

            $this->entityManager->flush();

            return $result;
        }

        $this->pluginLogger->debug('Trying to get component ObjectEntity with id: '.$componentId.'.');
        $component = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['id' => $componentId]);
        if ($component instanceof ObjectEntity === false) {
            $this->pluginLogger->error('No component ObjectEntity found with id: '.$componentId.'.');

            return null;
        }//end if

        $component = $this->rateComponent($component);

        $this->entityManager->flush();

        return $component->toArray();

    }//end enrichComponentsWithRating()


    /**
     * Rate a component.
     *
     * @param ObjectEntity $component The component to rate.
     *
     * @throws Exception
     *
     * @return ObjectEntity Dataset at the end of the handler.
     */
    public function rateComponent(ObjectEntity $component): ObjectEntity
    {
        $ratingSchema = $this->resourceService->getSchema($this->configuration['ratingSchema'], 'open-catalogi/open-catalogi-bundle');

        $ratingComponent = $this->ratingList($component);

        if (($rating = $component->getValue('rating')) === false) {
            $rating = new ObjectEntity();
            $rating->setEntity($ratingSchema);
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

        if (($descriptionObject = $component->getValue('description')) !== false) {
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

        if (($legalObject = $component->getValue('legal')) !== false) {
            $ratingArray = $this->ratingListService->rateLicense($legalObject, $ratingArray);

            if (($mainOwnerObject = $legalObject->getValue('mainCopyrightOwner')) !== false) {
                $ratingArray = $this->ratingListService->rateCopyOwner($mainOwnerObject, $ratingArray);
            }//end if

            if (($repoOwnerObject = $legalObject->getValue('repoOwner')) !== false) {
                $ratingArray = $this->ratingListService->rateRepoOwner($repoOwnerObject, $ratingArray);
            }//end if

            $ratingArray = $this->ratingListService->rateAuthorsFile($legalObject, $ratingArray);
        } else {
            $ratingArray['results'][] = 'Cannot rate the legal object because it is not set';
            $ratingArray['maxRating'] = ($ratingArray['maxRating'] + 2);
        }

        if (($maintenanceObject = $component->getValue('maintenance')) !== false) {
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
