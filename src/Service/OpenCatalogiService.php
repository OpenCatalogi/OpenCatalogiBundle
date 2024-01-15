<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\MappingService;
use App\Service\SynchronizationService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\ClientException;
use phpDocumentor\Reflection\Types\This;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\YamlEncoder;

class OpenCatalogiService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param CallService            $callService     The Call Service
     * @param SynchronizationService $syncService     The Synchronisation Service
     * @param MappingService         $mappingService  The Mapping Service
     * @param RatingService          $ratingService   The Rating Service.
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        RatingService $ratingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->syncService     = $syncService;
        $this->mappingService  = $mappingService;
        $this->ratingService   = $ratingService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;

        $this->configuration = [];
        $this->data          = [];

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
     * This function loops through the array with publiccode/opencatalogi files.
     *
     * @param array        $opencatalogiArray The opencatalogi array from the github api.
     * @param Source       $source            The github api source.
     * @param ObjectEntity $repository        The repository object.
     *
     * @return ObjectEntity|null The repository with the updated organization object
     * @throws Exception
     */
    public function handleOpencatalogiFile(array $opencatalogiArray, Source $source, ObjectEntity $repository, array $data, ?array $organizationArray = []): ?ObjectEntity
    {
        $opencatalogiMapping = $this->resourceService->getMapping($this->configuration['opencatalogiMapping'], 'open-catalogi/open-catalogi-bundle');
        $organizationSchema  = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
        if ($opencatalogiMapping instanceof Mapping === false
            || $organizationSchema instanceof Entity === false
        ) {
            return null;
        }

        $opencatalogi = $data['opencatalogi'];
        $sha = $data['sha'];

        if ($repository->getValue('source') === 'gitlab') {
            // Find the sync with the source and organization web_url.
            $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['web_url']);
            $opencatalogi['gitlab']           = $organizationArray['web_url'];

            if ($organizationArray['kind'] === 'group') {
                $opencatalogi['type']             = 'Organization';
            }

            if ($organizationArray['kind'] === 'user') {
                $opencatalogi['type']             = 'User';
            }
        }

        if ($repository->getValue('source') === 'github') {

            // Find the sync with the source and organization html_url.
            $organizationSync = $this->syncService->findSyncBySource($source, $organizationSchema, $organizationArray['html_url']);
            $opencatalogi['github']           = $organizationArray['html_url'];
            $opencatalogi['type']             = $organizationArray['type'];
        }

        // Check the sha of the sync with the url reference in the array.
        if ($this->syncService->doesShaMatch($organizationSync, $sha) === true) {
            return $organizationSync->getObject();
        }

        // Set the mapping to the sync object.
        $organizationSync->setMapping($opencatalogiMapping);

        // Synchronize the organization with the opencatalogi file.
        $organizationSync = $this->syncService->synchronize($organizationSync, $opencatalogi);

        // Persist the organization sync and organization objects.
        $this->entityManager->persist($organizationSync);
        $this->entityManager->persist($organizationSync->getObject());

        // Set the organization to the repository object and persist the repository.
        $repository->setValue('organisation', $organizationSync->getObject());
        $this->entityManager->persist($repository);

        $this->entityManager->flush();

        return $repository;

    }//end handleOpencatalogiFile()


}//end class
