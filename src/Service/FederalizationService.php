<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\InstallationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

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
     * A bool used to keep track if we are known in the Catalogi we are reading.
     *
     * @var boolean
     */
    private bool $weAreKnown;

    /**
     * The domain of this Catalogi installation, set through the getAppDomain() function.
     *
     * @var string
     */
    private string $currentDomain;

    /**
     * An array to keep track of synchronization id's we already synced (from the same source)
     *
     * @var array
     */
    private array $alreadySynced = [];


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
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style The SymfonyStyle.
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


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

        // Get the application domain we use the register this Catalogi to other Catalogi installations.
        $this->getAppDomain();

        // Safety check
        if ($this->catalogusEntity === null) {
            $this->logger->error('Could not find an entity for https://opencatalogi.nl/oc.catalogi.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return $data;
        }

        // Get all the catalogi
        $catalogi = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $this->catalogusEntity]);

        if (isset($this->style) === true) {
            $this->style->info('Found '.count($catalogi).' Catalogi');
        }

        // Sync them
        foreach ($catalogi as $catalogus) {
            $this->weAreKnown = false;
            $this->readCatalogus($catalogus);

            // Check if we/this Catalogi is known in the catalogus we just read. If not, make ourselves known.
            if ($this->weAreKnown === false) {
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
        if ($this->currentDomain === 'localhost') {
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

        $sourceObject = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $source->getValue('location')]);

        $newCatalogi = [
            "source" => [
                "name"        => preg_replace('/^api\./', '', $this->currentDomain).' Source',
                "description" => 'Source for: '.preg_replace('/^api\./', '', $this->currentDomain),
                "location"    => "https://$this->currentDomain",
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

        $this->logger->info('Looking at '.$source->getValue('name').' (@:'.$source->getValue('location').')', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        if (isset($this->style) === true) {
            $this->style->section('Looking at '.$source->getValue('name').' (@:'.$source->getValue('location').')');
        }

        $sourceObject = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $source->getValue('location')]);
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
        if (isset($this->style) === true) {
            $this->style->info('Found '.count($objects).' objects');
        }

        $synchonizedObjects  = [];
        $this->alreadySynced = [];

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

                if (isset($this->style) === true) {
                    $this->style->writeln('Flushed 100 objects...');
                    $this->style->writeln('Total synchronizations, incl. sub-objects: '.count($this->alreadySynced));
                }
            }
        }//end foreach

        $this->entityManager->flush();

        $this->logger->info('Synchronized '.count($this->alreadySynced).' objects', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        if (isset($this->style) === true) {
            $this->style->info('Synchronized '.count($this->alreadySynced).' objects');
        }

        // Now we can check if any objects where removed ->  Don't do this for now
        $synchronizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['gateway' => $source]);

        $counter = 0;
        foreach ($synchronizations as $synchronization) {
            if (in_array($synchronization->getSourceId(), $synchonizedObjects) === false) {
                $this->entityManager->remove($synchronization->getObject());

                $counter++;
            }
        }

        $this->logger->info('Removed '.$counter.' objects', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        if (isset($this->style) === true) {
            $this->style->info('Removed '.$counter.' objects');
        }

        $this->entityManager->flush();

        return $reportOut;

    }//end readCatalogus()


    /**
     * Checks if the source object contains a source, and if so, set the source that has been found.
     *
     * @param Entity $entity     The entity
     * @param array  $sourceSync The synchronization in the original data
     *
     * @return Synchronization The updated synchronization
     */
    private function getSourceSync(Entity $entity, array $sourceSync): Synchronization
    {
        // If this Source does not exist, create it.
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $sourceSync['source']['location']]);
        if ($source === null) {
            $source = new Source();
            $source->setName($sourceSync['source']['name']);
            $source->setDescription($sourceSync['source']['description']);
            $source->setLocation($sourceSync['source']['location']);
            $this->entityManager->persist($source);
        }

        $synchronization = $this->syncService->findSyncBySource($source, $entity, $sourceSync['sourceId']);

        $synchronization->setEndpoint($sourceSync['endpoint']);

        return $synchronization;

    }//end getSourceSync()


    /**
     * Handle en object found through the search endpoint of an external catalogus.
     *
     * @param array  $object The object to handle
     * @param Source $source The Source
     *
     * @return void|Synchronization
     */
    private function handleObject(array $object, Source $source): ?Synchronization
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
            // Let's not add ourselves as catalogi.
            if (isset($object['embedded']['source']['location']) === true
                && $object['embedded']['source']['location'] === $this->currentDomain
            ) {
                // We need to keep track of this, so we don't add ourselves to the $source Catalogi later.
                $this->weAreKnown = true;
                return null;
            }

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
        if (isset($object['_self']['synchronizations']) === true && count($object['_self']['synchronizations']) !== 0) {
            // We found something in a catalogi of which that catalogi is not the source, so we need to synchronize from the original source
            $baseSync = $object['_self']['synchronizations'][0];

            // Let's prevent loops, if we are the Source, don't create a Synchronization or Source for it.
            if ($baseSync['location'] === $this->currentDomain) {
                return null;
            }

            // Let's use the synchronization from that original source.
            $synchronization = $this->getSourceSync($entity, $baseSync);
        } else {
            // This catalogi is the source so let's roll. Note: this is the most reliable way to find id's of objects!
            $synchronization = $this->syncService->findSyncBySource($source, $entity, $object['_self']['id']);
            $synchronization->setEndpoint($endpoint);
        }

        // Let's improve performance a bit, by not repeating the same synchronizations.
        if (in_array($synchronization->getId()->toString(), $this->alreadySynced) === true) {
            return $synchronization;
        }

        $this->entityManager->persist($synchronization);

        // Lets sync
        $object                = $this->preventCascading($object, $source);
        $synchronization       = $this->syncService->synchronize($synchronization, $object);
        $this->alreadySynced[] = $synchronization->getId()->toString();
        return $synchronization;

    }//end handleObject()


    /**
     * Handle all subObjects in the embedded array. Creating (or updating) synchronizations and objects for all embedded objects.
     * Will also unset this embedded array after and set uuid's instead, so we have a non-cascading $object array before we synchronize.
     *
     * @param  array  $object The object to handle.
     * @param  Source $source The Source.
     * @return array The update object array.
     */
    private function preventCascading(array $object, Source $source): array
    {
        if (isset($object['embedded']) === false) {
            return $object;
        }

        foreach ($object['embedded'] as $key => $value) {
            if (is_array($value) === true && isset($value['_self']['schema']['ref']) === false) {
                foreach ($value as $subKey => $subValue) {
                    $synchronization = $this->handleObject($subValue, $source);
                    if ($synchronization === null) {
                        $object[$key][$subKey] = null;
                        continue;
                    }

                    $this->entityManager->persist($synchronization);
                    $object[$key][$subKey] = $synchronization->getObject()->getId()->toString();
                }

                continue;
            }

            $synchronization = $this->handleObject($value, $source);
            if ($synchronization === null) {
                $object[$key] = null;
                continue;
            }

            $this->entityManager->persist($synchronization);
            $object[$key] = $synchronization->getObject()->getId()->toString();
        }//end foreach

        unset($object['embedded']);

        return $object;

    }//end preventCascading()


    /**
     * Makes sure that we have the object entities that we need.
     *
     * @return void
     */
    private function prepareObjectEntities(): void
    {
        if (isset($this->catalogusEntity) === false) {
            $this->catalogusEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.catalogi.schema.json']);
            if ($this->catalogusEntity === null) {
                $this->logger->error('Could not find an entity for https://opencatalogi.nl/oc.catalogi.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
        }

        if (isset($this->componentEntity) === false) {
            $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json']);
            if ($this->componentEntity === null) {
                $this->logger->error('Could not find an entity for https://opencatalogi.nl/oc.component.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
        }

        if (isset($this->organisationEntity) === false) {
            $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
            if ($this->organisationEntity === null) {
                $this->logger->error('Could not find an entity for https://opencatalogi.nl/oc.organisation.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
        }

        if (isset($this->applicationEntity) === false) {
            $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.application.schema.json']);
            if ($this->applicationEntity === null) {
                $this->logger->error('Could not find an entity for https://opencatalogi.nl/oc.application.schema.json', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
        }

    }//end prepareObjectEntities()


    /**
     * Gets de default application, else the first other application. And gets the first domain from it that isn't localhost.
     * This currentDomain is also use to prevent 'federalization sync loops', where we would try to synchronize objects from other Catalogi that actually originated in this/the current Opencatalogi.
     *
     * @param int $key A key used to find a random application, if necessary.
     *
     * @return void
     */
    private function getAppDomain(int $key=0): void
    {
        $this->currentDomain = 'localhost';

        // First try and find the default application.
        $defaultApplication = $application = $this->entityManager->getRepository('App:Application')->findOneBy(['reference' => 'https://docs.commongateway.nl/application/default.application.json']);
        if ($defaultApplication === null) {
            $applications = $this->entityManager->getRepository('App:Application')->findAll();
            if (count($applications) === $key) {
                $this->logger->error('Could not find an Application for federalization', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

                return;
            }

            // If we couldn't find the default application, take the first other application we can find.
            $application = $applications[$key];
        }

        // If this application has no domains or only the domain localhost, try looking for another application.
        if (empty($application->getDomains())
            || (count($application->getDomains()) === 1 && $application->getDomains()[0] === 'localhost')
        ) {
            if ($defaultApplication !== null) {
                $this->logger->error('The Default Application does not have a domain (or only the domain localhost)', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

                return;
            }

            $this->getAppDomain($key + 1);

            return;
        }

        // Find the first domain that isn't localhost.
        foreach ($application->getDomains() as $domain) {
            if ($domain !== 'localhost') {
                $this->currentDomain = $domain;
                return;
            }
        }

    }//end getAppDomain()


}//end class
