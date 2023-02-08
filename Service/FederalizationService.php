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
use Symfony\Component\Console\Style\SymfonyStyle;
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
    private SynchronizationService $synchronizationService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

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
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        CommonGroundService $commonGroundService,
        CallService $callService,
        SynchronizationService $synchronizationService
    ) {
        $this->entityManager = $entityManager;
        $this->session = $session;
        $this->commonGroundService = $commonGroundService;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
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

        return $this;
    }

    /**
     * Handles the sync all catalogi action from the catalogi handler.
     *
     * @param array $data
     * @param array $configuration
     *
     * @return array
     */
    public function catalogiHandler(array $data = [], array $configuration = []): array
    {

        // Setup base data
        $this->prepareObjectEntities();

        // Savety cheek
        if (!$this->catalogusEntity) {
            (isset($this->io) ? $this->io->error('Could not find a entity for https://opencatalogi.nl/catalogi.schema.json') : '');

            return $data;
        }

        // Get al the catalogi
        $catalogi = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity'=>$this->catalogusEntity]);

        // Sync them
        foreach ($catalogi as $catalogus) {
            $this->readCatalogus($catalogus);
        }

        return $data;
    }

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
        if ($catalogus->getEntity->getReference() != 'https://opencatalogi.nl/catalogi.schema.json') {
            (isset($this->io) ? $this->io->error('The suplied Object is not of the type https://opencatalogi.nl/catalogi.schema.json') : '');

            return $reportOut;
        }

        // Lets get the source for the catalogus
        if (!$source = $catalogus->getValue('source')) {
            (isset($this->io) ? $this->io->error('The catalogi '.$catalogus->getName.' doesn\'t have an valid source') : '');

            return $reportOut;
        }

        (isset($this->io) ? $this->io->info('Looking at '.$source->getName().'(@:'.$source->getLocation().')') : '');
        // Lets grap ALL the objects for an external source
        $objects = json_decode($this->callService->call(
            $source,
            '/search/',
            'GET',
            ['query'=> ['limit'=>10000]]
        )->getBody()->getContents(), true)['results'];

        (isset($this->io) ? $this->io->writeln(['Found '.count($objects).' objects']) : '');

        $synchonizedObjects = [];

        (isset($this->io) ? $this->io->progressStart(count($objects)) : '');
        // Handle new objects
        $counter = 0;
        foreach ($objects as $key => $object) {
            $counter++;
            // Lets make sure we have a reference
            if (!isset($object['_self']['schema']['ref'])) {
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

            (isset($this->io) ? $this->io->progressAdvance() : '');
        }

        (isset($this->io) ? $this->io->progressFinish() : '');

        $this->entityManager->flush();

        /* Don't do this for now
        (isset($this->io)?$this->io->writeln(['','Looking for objects to remove']):'');
        // Now we can check if any objects where removed
        $synchonizations = $this->entityManager->getRepository('App:Synchronization')->findBy(['gateway' =>$source]);

        (isset($this->io)?$this->io->writeln(['Currently '.count($synchonizations).' object attached to this source']):'');
        $counter=0;
        foreach ($synchonizations as $synchonization){
            if(!in_array($synchonization->getSourceId(), $synchonizedObjects)){
                $this->entityManager->remove($synchonization->getObject());

                (isset($this->io)?$this->io->writeln(['Removed '.$synchonization->getSourceId()]):'');
                $counter++;
            }
        }
        (isset($this->io)?$this->io->writeln(['Removed '.$counter.' object attached to this source']):'');


        $this->entityManager->flush();
        */

        return $reportOut;
    }

    /**
     * Handle en object found trough the search endpoint of an external catalogus.
     *
     * @param array $object
     *
     * @return void
     */
    public function handleObject(array $object, Gateway $source): ?Synchronization
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
        if (!$synchonization = $this->entityManager->getRepository('App:Synchronization')->findOneBy(['sourceId' =>$externalId])) {
            $synchonization = new Synchronization($source, $entity);
            $synchonization->setSourceId($object['id']);
        }

        if (isset($object['_self'])) {
            unset($object['_self']);
        }

        // Lets sync
        return $this->synchronizationService->synchronize($synchonization, $object);
    }

    /**
     * Makes sure that we have the object entities that we need.
     *
     * @return void
     */
    public function prepareObjectEntities(): void
    {
        if (!isset($this->catalogusEntity)) {
            $this->catalogusEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/catalogi.schema.json']);
            (!$this->applicationEntity && isset($this->io) ? $this->io->error('Could not find a entity for https://opencatalogi.nl/catalogi.schema.json') : '');
        }
        if (!isset($this->componentEntity)) {
            $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/component.schema.json']);
            (!$this->applicationEntity && isset($this->io) ? $this->io->error('Could not find a entity for https://opencatalogi.nl/component.schema.json') : '');
        }
        if (!isset($this->organisationEntity)) {
            $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/organisation.schema.json']);
            (!$this->applicationEntity && isset($this->io) ? $this->io->error('Could not find a entity for https://opencatalogi.nl/organisation.schema.json') : '');
        }
        if (!isset($this->applicationEntity)) {
            $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' =>'https://opencatalogi.nl/application.schema.json']);
            (!$this->applicationEntity && isset($this->io) ? $this->io->error('Could not find a entity for https://opencatalogi.nl/application.schema.json') : '');
        }
    }
}
