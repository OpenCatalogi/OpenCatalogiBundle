<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Service to call synchronyse catalogi.
 *
 * This service provides way for catalogi to use each others indexes.
 *
 * @Author Wilco Louwerse <wilco@conduction.nl>, Robert Zondervan <robert@conduction.nl>, Ruben van der Linde <ruben@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
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
     * @param SessionInterface       $session       The session interface
     * @param CallService            $callService   The Call Service
     * @param SynchronizationService $syncService   The synchronization service
     * @param LoggerInterface        $pluginLogger  The plugin version of the logger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        CallService $callService,
        SynchronizationService $syncService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->session       = $session;
        $this->callService   = $callService;
        $this->syncService   = $syncService;
        $this->logger        = $pluginLogger;

    }//end __construct()


    /**
     * Handles the sync all catalogi action from the catalogi handler.
     *
     * @param array $data          The data suplied to the handler
     * @param array $configuration Optional configuration
     *
     * @return array THe result data from the handler
     */
    public function catalogiHandler(array $data=[], array $configuration=[]): array
    {
        // Setup base data
        $this->prepareObjectEntities();

        // Safety check
        if ($this->catalogusEntity === null) {
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.catalogi.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return $data;
        }

        // Get all the catalogi
        $catalogi = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $this->catalogusEntity]);

        // Sync them
        foreach ($catalogi as $catalogus) {
            $reportOut = $this->readCatalogus($catalogus);

            // Check if we/this Catalogi is known in the catalogus we just read. If not, make ourselves known.
            if (in_array('myDomain', array_column($reportOut['catalogi'], 'location')) === false) {
                $this->makeOurselvesKnown($catalogus);
            }
        }

        return $data;

    }//end catalogiHandler()


    /**
     * Will add information of ('us') the current Catalogi, to the given $catalogus source.
     *
     * @param ObjectEntity $catalogus The catalogus where we will be creating a new Catalogi object.
     *
     * @return void
     */
    private function makeOurselvesKnown(ObjectEntity $catalogus)
    {
        // Make sure we never add localhost as catalogi to another catalogi.
        if ('myDomain' === 'localhost') {
            return;
        }

        // Check if the past object is a Catalogi object.
        if ($catalogus->getEntity()->getReference() !== 'https://opencatalogi.nl/oc.catalogi.schema.json') {
            // readCatalogus() function will always (already) log an error.
            return;
        }

        // Let's get the source for the catalogus
        $source = $catalogus->getValue('source');
        if ($source === null) {
            // readCatalogus() function will always (already) log an error.
            return;
        }

        $sourceObject = $this->entityManager->getRepository('App:Gateway')->findBy(['location' => $source->getValue('location')]);

        $newCatalogi = [
            "source" => [
                "name"        => "myName",
                "description" => "myDescription",
                "location"    => "myDomain",
            ],
        ];

        $this->callService->call(
            $sourceObject,
            '/api/catalogi',
            'POST',
            ['body' => json_encode($newCatalogi)]
        );

    }//end makeOurselvesKnown()


    /**
     * Get and handle oll the objects of a catalogi specific.
     *
     * @param ObjectEntity $catalogus The catalogus that should be read
     *
     * @return array An report of the found objects
     */
    public function readCatalogus(ObjectEntity $catalogus): array
    {
        // Basis setup
        $reportOut = [];

        // Check if the past object is a Catalogi object.
        if ($catalogus->getEntity()->getReference() !== 'https://opencatalogi.nl/oc.catalogi.schema.json') {
            $this->logger->error('The supplied Object is not of the type https://opencatalogi.nl/catalogi.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return $reportOut;
        }

        // Let's get the source for the catalogus
        $source = $catalogus->getValue('source');
        if ($source === null) {
            $this->logger->error('The catalogi '.$catalogus->getName.' doesn\'t have an valid source', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return $reportOut;
        }

        $this->logger->info('Looking at '.$source->getValue('name').'(@:'.$source->getValue('location').')', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $sourceObject = $this->entityManager->getRepository('App:Gateway')->findBy(['location' => $source->getValue('location')]);
        if ($sourceObject === null) {
            $sourceObject = new Source();
            $sourceObject->setName($source->getValue('name'));
            $sourceObject->setDescription($source->getValue('description'));
            $sourceObject->setLocation($source->getValue('location'));
            $this->entityManager->persist($sourceObject);
            $this->entityManager->flush();
        }

        // Let's grab ALL the objects for an external source
        $objects = json_decode(
            $this->callService->call(
                $sourceObject,
                '/api/federalization',
                'GET',
                ['query' => ['limit' => 10000]]
            )->getBody()->getContents(),
            true
        )['results'];

        $this->logger->info('Found '.count($objects).' objects', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $synchonizedObjects = [];

        // Handle new objects
        $counter = 0;
        foreach ($objects as $object) {
            $counter++;
            // Let's make sure we have a reference
            if (isset($object['_self']['schema']['ref']) === false) {
                continue;
            }

            $synchronization = $this->handleObject($object, $sourceObject);
            if ($synchronization === null) {
                continue;
            }

            $synchonizedObjects[] = $synchronization->getSourceId();
            $this->entityManager->persist($synchronization);

            // Lets save every so often
            if ($counter >= 100) {
                $counter = 0;
                $this->entityManager->flush();
            }
        }//end foreach

        $this->entityManager->flush();

        // Now we can check if any objects where removed ->  Don't do this for now
        $synchronizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['gateway' => $source]);

        $counter = 0;
        foreach ($synchronizations as $synchronization) {
            if (in_array($synchronization->getSourceId(), $synchonizedObjects) === false) {
                $this->entityManager->remove($synchronization->getObject());

                $counter++;
            } else if ($synchronization->getObject()->getEntity() === $this->catalogusEntity) {
                $reportOut['catalogi'][] = [
                    "location" => $synchronization->getObject()->getValue('location'),
                ];
            }
        }

        $this->logger->info('Removed '.$counter.' objects', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $this->entityManager->flush();

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

        // If this Source does not exist, create it.
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $sourceSync['gateway']['location']]);
        if ($source === null) {
            $source = new Source();
            $source->setName($sourceSync['gateway']['name']);
            $source->setDescription($sourceSync['gateway']['description']);
            $source->setLocation($sourceSync['gateway']['location']);
            $this->entityManager->persist($source);
        }

        $synchronization->setSource($source);

        return $synchronization;

    }//end setSourcesSource()


    /**
     * Handle en object found trough the search endpoint of an external catalogus.
     *
     * @param array  $object The object to handle
     * @param Source $source The Source
     *
     * @return void|Synchronization
     */
    public function handleObject(array $object, Source $source): ?Synchronization
    {
        // Let's make sure we have a reference, just in case this function gets used separately
        if (isset($object['_self']['schema']['ref']) === false) {
            return null;
        }

        // Get The entities
        $this->prepareObjectEntities();

        // Do our Magic
        $reference = $object['_self']['schema']['ref'];

        switch ($reference) {
        case 'https://opencatalogi.nl/oc.catalogi.schema.json':
            $entity   = $this->catalogusEntity;
            $endpoint = '/api/catalogi';
            break;
        case 'https://opencatalogi.nl/oc.organisation.schema.json':
            $entity   = $this->organisationEntity;
            $endpoint = '/api/organisations';
            break;
        case 'https://opencatalogi.nl/oc.component.schema.json':
            $entity   = $this->componentEntity;
            $endpoint = '/api/components';
            break;
        case 'https://opencatalogi.nl/oc.application.schema.json':
            $entity   = $this->applicationEntity;
            $endpoint = '/api/applications';
            break;
        default:
            // Unknown type, lets output something to IO
            return null;
        }//end switch

        // Let's handle whatever we found
        if (isset($object['_self']['synchronisations']) === true && count($object['_self']['synchronisations']) !== 0) {
            // We found something in a catalogi of which that catalogi is not the source, so we need to synchronize from the original source
            $baseSync   = $object['_self']['synchronisations'][0];
            $externalId = $baseSync['sourceId'];
        } else {
            // This catalogi is the source so let's roll
            $externalId = $object['_id'];
        }

        // Let's see if we already have a synchronisation.
        $synchronization = $this->entityManager->getRepository('App:Synchronization')->findOneBy(['sourceId' => $externalId]);
        if ($synchronization === null) {
            // If not, we create a synchronization for this object.
            $synchronization = new Synchronization($source, $entity);
            $synchronization->setEndpoint($endpoint);
            $synchronization->setSourceId($externalId);
        }

        // If we found something in a catalogi of which that catalogi is not the source lets use the synchronization from that original source instead.
        if (isset($baseSync) === true) {
            $synchronization = $this->setSourcesSource($synchronization, $baseSync);
        }

        $this->entityManager->persist($synchronization);

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
            $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.component.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($this->organisationEntity) === false) {
            $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.organisation.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($this->applicationEntity) === false) {
            $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.application.schema.json']);
            $this->logger->error('Could not find a entity for https://opencatalogi.nl/oc.application.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

    }//end prepareObjectEntities()


}//end class
