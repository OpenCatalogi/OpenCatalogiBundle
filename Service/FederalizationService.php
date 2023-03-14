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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to call synchronyse catalogi.
 *
 * This service provides way for catalogi to use each others indexes.
 *
 * @Author Robert Zondervan <robert@conduction.nl>, Ruben van der Linde <ruben@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package open-catalogi/open-catalogi-bundle
 * @category Service
 */
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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;

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
     * @param EntityManagerInterface $entityManager The entity manager
     * @param SessionInterface $session The session interface
     * @param CommonGroundService $commonGroundService The commonground service
     * @param CallService $callService The Call Service
     * @param SynchronizationService $synchronizationService The synchronization service
     * @param LoggerInterface $pluginLogger The plugin version of the loger interface
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
     * @param array $data The data suplied to the handler
     * @param array $configuration Optional configuration
     *
     * @return array THe result data from the handler
     */
    public function catalogiHandler(array $data = [], array $configuration = []): array
    {
        // Setup base data
        $this->prepareObjectEntities();

        // Savety cheek
        if (!$this->catalogusEntity) {
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.catalogi.schema.json',['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        // Basis setup
        $reportOut = [];

        // Check if the past object is a
        if ($catalogus->getEntity()->getReference() != 'https://opencatalogi.nl/oc.catalogi.schema.json') {
            $this->logger->error('The suplied Object is not of the type https://opencatalogi.nl/catalogi.schema.json',['plugin'=>'open-catalogi/open-catalogi-bundle']);

            return $reportOut;
        }

        // Lets get the source for the catalogus
        if (!$source = $catalogus->getValue('source')) {
            $this->logger->error('The catalogi '.$catalogus->getName.' doesn\'t have an valid source',['plugin'=>'open-catalogi/open-catalogi-bundle']);

            return $reportOut;
        }

        $this->logger->info('Looking at '.$source->getName().'(@:'.$source->getValue('location').')',['plugin'=>'open-catalogi/open-catalogi-bundle']);

        if (!$sourceObject = $this->entityManager->getRepository('App:Gateway')->findBy(['location' => $source->getValue('location')])) {
            $sourceObject = new Source();
            $sourceObject->setLocation($source->getValue('location'));
        }

        // Lets grap ALL the objects for an external source
        $objects = json_decode($this->callService->call(
            $sourceObject,
            '/api/search',
            'GET',
            ['query'=> ['limit'=>10000]]
        )->getBody()->getContents(), true)['results'];

        $this->logger->info('Found '.count($objects).' objects',['plugin'=>'open-catalogi/open-catalogi-bundle']);

        $synchonizedObjects = [];

        // Handle new objects
        $counter = 0;
        foreach ($objects as $key => $object) {
            $counter++;
            // Lets make sure we have a reference
            if (!isset($object['_self']['schema']['ref'])) {
                continue;
            }
            $synchronization = $this->handleObject($object, $sourceObject);
            if ($synchronization === null) {
                continue;
            }
            $synchonizedObjects[] = $synchronization->getSourceId();
            $this->entityManager->persist($synchronization);

            // Lets save every so ofthen
            if ($counter >= 100) {
                $counter = 0;
                $this->entityManager->flush();
            }

        }


        $this->entityManager->flush();

        /* Don't do this for now
        // Now we can check if any objects where removed
        $synchonizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['gateway' =>$source]);

        $counter=0;
        foreach ($synchonizations as $synchonization){
            if(!in_array($synchonization->getSourceId(), $synchonizedObjects)){
                $this->entityManager->remove($synchonization->getObject());

                $counter++;
            }
        }


        $this->entityManager->flush();
        */

        return $reportOut;
    }//end readCatalogus()

    /**
     * Checks if the source object contains a source, and if so, set the source that has been found.
     *
     * @param Synchronization $synchronization The synchronization to update
     * @param array           $sourceSync      The synchronization in the original data
     *
     * @return Synchronization The updated synchronization
     */
    private function setSourcesSource(Synchronization $synchronization, array $sourceSync): Synchronization
    {
        $synchronization->setEndpoint($sourceSync['endpoint']);
        $synchronization->setSourceId($sourceSync['sourceId']);

        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $sourceSync['gateway']['location']]);
        if ($sourceSync === null) {
            $source = new Source();
            $source->setName($sourceSync['gateway']['name']);
            $source->setLocation($sourceSync['gateway']['location']);
        }
        $synchronization->setSource($source);

        return $synchronization;
    }//end setSourcesSource()

    /**
     * Handle en object found trough the search endpoint of an external catalogus.
     *
     * @param array $object THe object to handle
     *
     * @return void|Synchronization
     */
    public function handleObject(array $object, Source $source): ?Synchronization
    {
        // Lets make sure we have a reference, just in case this function gets ussed seperatly
        if (!isset($object['_self']['schema']['ref'])) {
            return null;
        }

        // Get The entities
        $this->prepareObjectEntities();

        // Do our Magic
        $reference = $object['_self']['schema']['ref'];

        switch ($reference) {
            case 'https://opencatalogi.nl/oc.catalogi.schema.json':
                $entity = $this->catalogusEntity;
                break;
            case 'https://opencatalogi.nl/oc.organisation.schema.json':
                $entity = $this->organisationEntity;
                break;
            case 'https://opencatalogi.nl/oc.component.schema.json':
                $entity = $this->componentEntity;
                break;
            case 'https://opencatalogi.nl/oc.application.schema.json':
                $entity = $this->applicationEntity;
                break;
            default:
                // Unknown type, lets output something to IO
                return null;
        }

        // Lets handle whatever we found
        if (isset($object['_self']['synchronisations']) and count($object['_self']['synchronisations']) != 0) {
            // We found something in a cataogi of witch that catalogus is not the source, so we need to synchorniste to that source set op that source if we dont have it yet etc etc
            $baseSync = $object['_self']['synchronisations'][0];
            $externalId = $baseSync['id'];

            // Check for source
            if (!$source = $this->entityManager->getRepository('App:Gateway')->findBy(['location' =>$baseSync['source']['location']])) {
                $source = new Source();
                $source->setName($baseSync['source']['name']);
                $source->setDescription($baseSync['source']['description']);
                $source->setLocation($baseSync['source']['location']);
            }
        } else {
            // This catalogi is teh source so lets roll
            $externalId = $object['id'];
        }

        // Lets se if we already have an synchronisation
        if (!$synchronization = $this->entityManager->getRepository('App:Synchronization')->findOneBy(['sourceId' =>$externalId])) {
            $synchronization = new Synchronization($source, $entity);
            $synchronization->setSourceId($object['id']);
        }

        $this->entityManager->persist($synchronization);

        if (isset($object['_self']['synchronizations'][0])) {
            $synchronization = $this->setSourcesSource($synchronization, $object['_self']['synchronizations'][0]);
        }

        // Lets sync
        return $this->syncService->synchronize($synchronization, $object);
    }//end handleObject()

    /**
     * Makes sure that we have the object entities that we need.
     *
     * @return void
     */
    public function prepareObjectEntities(): void
    {
        if (isset($this->catalogusEntity) === false) {
            $this->catalogusEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.catalogi.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.catalogi.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($this->componentEntity) === false) {
            $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/oc.component.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.component.schema.json',['plugin'=>'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($this->organisationEntity) === false) {
            $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/oc.organisation.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.organisation.schema.json',['plugin'=>'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($this->applicationEntity) === false) {
            $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/oc.application.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.application.schema.json',['plugin'=>'open-catalogi/open-catalogi-bundle']);
        }
    }//end prepareObjectEntities()
}//end class
