<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class FederalizationService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SessionInterface
     */
    private SessionInterface $session;

    /**
     * @var CommonGroundService
     */
    private CommonGroundService $commonGroundService;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    // Lets prevent unnesecery database calls
    /**
     * @var Entity
     */
    private Entity $catalogusEntity;

    /**
     * @var Entity
     */
    private Entity $componentEntity;

    /**
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var Entity
     */
    private Entity $applicationEntity;

    /**
     * @param EntityManagerInterface $entityManager          EntityManagerInterface
     * @param SessionInterface       $session                SessionInterface
     * @param CommonGroundService    $commonGroundService    CommonGroundService
     * @param CallService            $callService            CallService
     * @param SynchronizationService $synchronizationService SynchronizationService
     * @param LoggerInterface        $mappingLogger          The logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        CommonGroundService $commonGroundService,
        CallService $callService,
        SynchronizationService $syncService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->session = $session;
        $this->commonGroundService = $commonGroundService;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->logger = $pluginLogger;
    }//end __construct()

    /**
     * Handles the sync all catalogi action from the catalogi handler.
     *
     * @param array $data          The data
     * @param array $configuration The configuration
     *
     * @return array
     */
    public function catalogiHandler(array $data = [], array $configuration = []): array
    {

        // Setup base data
        $this->prepareObjectEntities();

        // Saverty cheek
        if ($this->catalogusEntity === null) {
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/catalogi.schema.json');

            return $data;
        }

        // Get al the catalogi
        $catalogi = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity'=>$this->catalogusEntity]);

        // Sync them
        foreach ($catalogi as $catalogus) {
            $this->readCatalogus($catalogus);
        }

        return $data;
    }//end catalogiHandler()

    /**
     * Get and handle oll the objects of an catalogi specific.
     *
     * @param ObjectEntity $catalogus The catalogus that should be read
     *
     * @return array An report of the found objects
     */
    public function readCatalogus(ObjectEntity $catalogus): array
    {

        // Basis setup.
        $reportOut = [];

        // Check if the past object is a.
        if ($catalogus->getEntity->getReference() !== 'https://opencatalogi.nl/catalogi.schema.json') {
            $this->logger->error('The suplied Object is not of the type https://opencatalogi.nl/catalogi.schema.json');

            return $reportOut;
        }

        // Lets get the source for the catalogus.
        $source = $catalogus->getValue('source');
        if ($source === null) {
            $this->logger->error('The catalogi '.$catalogus->getName.' doesn\'t have an valid source');

            return $reportOut;
        }

        $this->logger->info('Looking at '.$source->getName().'(@:'.$source->getLocation().')');

        // Lets grap ALL the objects for an external source.
        $objects = json_decode($this->callService->call(
            $source,
            '/search/',
            'GET',
            ['query'=> ['limit'=>10000]]
        )->getBody()->getContents(), true)['results'];

        $this->logger->debug('Found '.count($objects).' objects');

        $synchonizedObjects = [];

        // Handle new objects
        $counter = 0;
        foreach ($objects as $object) {
            $counter++;
            // Lets make sure we have a reference
            if (isset($object['_self']['schema']['ref']) === false) {
                continue;
            }

            $synchonization = $this->handleObject($object, $source);
            $synchonizedObjects[] = $synchonization->getSourceId();
            $this->entityManager->persist($synchonization);

            // Lets save every so ofthen
            if ($counter >= 100) {
                $counter = 0;
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return $reportOut;
    }//end readCatalogus()

    /**
     * Handle en object found trough the search endpoint of an external catalogus.
     *
     * This method breaks complexity rules becouse of the switch. However sins a siwtch provides better performance a design decicion was made to keep the current code
     *
     * @param array   $object The object
     * @param Gateway $source The Source
     *
     * @return void
     */
    public function handleObject(array $object, Gateway $source): ?Synchronization
    {
        // Lets make sure we have a reference, just in case this function gets ussed seperatly
        if (!isset($object['_self']['schema']['ref'])) {
            return null;
        }

        // Get The entities.
        $this->prepareObjectEntities();

        // Do our Magic.
        $reference = $object['_self']['schema']['ref'];

        switch ($reference) {
            case 'https://opencatalogi.nl/catalogi.schema.json':
                $entity = $this->catalogusEntity;
                break;
            case 'https://opencatalogi.nl/organisation.schema.json':
                $entity = $this->organisationEntity;
                break;
            case 'https://opencatalogi.nl/component.schema.json':
                $entity = $this->componentEntity;
                break;
            case 'https://opencatalogi.nl/application.schema.json':
                $entity = $this->applicationEntity;
                break;
            default:
                // Unknown type, lets output something to IO
                return null;
        }//end switch

        // Lets handle whatever we found.
        if (isset($object['_self']['synchronisations']) === true && count($object['_self']['synchronisations']) !== 0) {
            // We found something in a cataogi of witch that catalogus is not the source, so we need to synchorniste to that source set op that source if we dont have it yet etc etc
            $baseSync = $object['_self']['synchronisations'][0];
            $externalId = $baseSync['id'];

            // Check for source
            !$source = $this->entityManager->getRepository('App:Gateway')->findBy(['location' =>$baseSync['source']['location']]);
            if ($source === null) {
                $source = new Source();
                $source->setName($baseSync['source']['name']);
                $source->setDescription($baseSync['source']['description']);
                $source->setLocation($baseSync['source']['location']);
            }
        } else {
            // This catalogi is teh source so lets roll.
            $externalId = $object['id'];
        }

        // Lets se if we already have an synchronisation.
        $synchonization = $this->entityManager->getRepository('App:Synchronization')->findOneBy(['sourceId' =>$externalId]);
        if ($synchonization === null) {
            $synchonization = new Synchronization($source, $entity);
            $synchonization->setSourceId($object['id']);
        }

        if (isset($object['_self']) === true) {
            unset($object['_self']);
        }

        // Lets sync
        return $this->syncService->synchronize($synchonization, $object);
    }//end handleObject()

    /**
     * Makes sure that we have the object entities that we need.
     *
     * @return void
     */
    public function prepareObjectEntities(): void
    {
        if (isset($this->catalogusEntity) === false) {
            $this->catalogusEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/catalogi.schema.json']);

            if ($this->applicationEntity === null) {
                $this->logger->error('Could not find a entity for https://opencatalogi.nl/catalogi.schema.json');
            }
        }

        if (isset($this->componentEntity) === false) {
            $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/component.schema.json']);

            if ($this->componentEntity === null) {
                $this->logger->error('Could not find a entity for https://opencatalogi.nl/component.schema.json');
            }
        }

        if (isset($this->organisationEntity) === false) {
            $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/organisation.schema.json']);

            if ($this->organisationEntity === null) {
                $this->logger->error('Could not find a entity for https://opencatalogi.nl/organisation.schema.json');
            }
        }

        if (isset($this->applicationEntity) === false) {
            $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/application.schema.json']);

            if ($this->applicationEntity === null) {
                $this->logger->error('Could not find a entity for https://opencatalogi.nl/application.schema.json');
            }
        }
    }//end prepareObjectEntities()
}
