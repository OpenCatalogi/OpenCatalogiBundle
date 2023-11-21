<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
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
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

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
     * @param SynchronizationService $syncService       The Synchronization Service.
     * @param LoggerInterface        $pluginLogger      The plugin version of the logger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        RatingListService $ratingListService,
        GatewayResourceService $resourceService,
        SynchronizationService $syncService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager     = $entityManager;
        $this->ratingListService = $ratingListService;
        $this->resourceService   = $resourceService;
        $this->syncService       = $syncService;
        $this->pluginLogger      = $pluginLogger;
        $this->configuration     = [];
        $this->data              = [];

    }//end __construct()


    /**
     * Override configuration from other services.
     *
     * @param array $configuration The new configuration array.
     *
     * @return void
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;

    }//end setConfiguration()


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
     * Rate the components of the repository
     *
     * @param ObjectEntity                               $repository The repository object.
     * @param Source                                     $source     The source of the repository.
     * @param array The repository array from the source.
     *
     * @throws Exception
     *
     * @return ObjectEntity Dataset at the end of the handler.
     */
    public function rateRepoComponents(ObjectEntity $repository, Source $source, array $repositoryArray): ObjectEntity
    {

        foreach ($repository->getValue('components') as $component) {
            $this->rateComponent($component, $source, $repositoryArray);
        }

        return $repository;

    }//end rateRepoComponents()


    /**
     * Rate the components of the repository
     *
     * @param ObjectEntity                               $component The component object.
     * @param Source                                     $source    The source of the repository.
     * @param array The repository array from the source.
     *
     * @throws Exception
     *
     * @return ObjectEntity Dataset at the end of the handler.
     */
    public function rateComponent(ObjectEntity $component, Source $source, array $repositoryArray): ObjectEntity
    {
        $ratingSchema  = $this->resourceService->getSchema($this->configuration['ratingSchema'], 'open-catalogi/open-catalogi-bundle');
        $ratingMapping = $this->resourceService->getMapping($this->configuration['ratingMapping'], 'open-catalogi/open-catalogi-bundle');

        // Get the source id of the component.
        $sourcId = $component->getSynchronizations()->first()->getSourceId();

        // Find the sync with the component source id.
        $ratingSync = $this->syncService->findSyncBySource($source, $ratingSchema, $sourcId);
        $ratingSync->setMapping($ratingMapping);

        // Get the rating array.
        $ratingArray = $this->ratingList($component, $repositoryArray);

        // Sync the rating array.
        $ratingSync = $this->syncService->synchronize($ratingSync, $ratingArray);

        // Set the rating object to the component.
        $component->setValue('rating', $ratingSync->getObject());
        $this->entityManager->persist($component);
        $this->entityManager->flush();

        $this->pluginLogger->debug("Created rating ({$ratingSync->getObject()->getId()->toString()}) for component ObjectEntity with id: {$component->getId()->toString()}");

        return $component;

    }//end rateComponent()


    /**
     * Rates a component.
     *
     * @param ObjectEntity $component       The component to rate.
     * @param array        $repositoryArray The repository array from the source.
     *
     * @throws Exception|GuzzleException
     *
     * @return ObjectEntity|null Dataset at the end of the handler.
     */
    public function ratingList(ObjectEntity $component, array $repositoryArray): ?array
    {
        $ratingArray = [
            'rating'    => 0,
            'maxRating' => 0,
            'results'   => [],
        ];

        $this->ratingListService->setConfiguration($this->configuration);

        $ratingArray = $this->ratingListService->rateName($component, $ratingArray);
        $ratingArray = $this->ratingListService->rateUrl($component, $ratingArray, $repositoryArray);
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
        }

        if (($descriptionObject = $component->getValue('description')) === false) {
            $ratingArray['results'][] = 'Cannot rate the description object because it is not set';
            $ratingArray['maxRating']++;
        }

        if (($legalObject = $component->getValue('legal')) !== false) {
            $ratingArray = $this->ratingListService->rateLicense($legalObject, $ratingArray);

            if (($mainOwnerObject = $legalObject->getValue('mainCopyrightOwner')) !== false
            ) {
                $ratingArray = $this->ratingListService->rateCopyOwner($mainOwnerObject, $ratingArray);
            }//end if

            if (($repoOwnerObject = $legalObject->getValue('repoOwner')) !== false
            ) {
                $ratingArray = $this->ratingListService->rateRepoOwner($repoOwnerObject, $ratingArray);
            }//end if

            $ratingArray = $this->ratingListService->rateAuthorsFile($legalObject, $ratingArray);
        }

        if (($legalObject = $component->getValue('legal')) === false) {
            $ratingArray['results'][] = 'Cannot rate the legal object because it is not set';
            $ratingArray['maxRating']++;
        }

        if (($maintenanceObject = $component->getValue('maintenance')) !== false) {
            $ratingArray = $this->ratingListService->rateType($maintenanceObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateContractors($maintenanceObject, $ratingArray);
            $ratingArray = $this->ratingListService->rateContacts($maintenanceObject, $ratingArray);
        }

        if (($maintenanceObject = $component->getValue('maintenance')) === false) {
            $ratingArray['results'][] = 'Cannot rate the maintenance object because it is not set';
            $ratingArray['maxRating']++;
        }

        return $ratingArray;

    }//end ratingList()


}//end class
