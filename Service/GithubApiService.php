<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GithubApiService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param CallService            $callService            The Call Service
     * @param CacheService           $cacheService           The Cache Service
     * @param SynchronizationService $synchronizationService The Synchronization Service
     * @param MappingService         $mappingService         The Mapping Service
     * @param LoggerInterface        $pluginLogger           The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        CacheService $cacheService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->cacheService = $cacheService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->logger = $pluginLogger;

        $this->configuration = [];
        $this->data = [];
    }//end __construct()

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
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);
        $this->githubPubliccodeService->setStyle($io);

        return $this;
    }//end setStyle()

    /**
     * Get a source by reference.
     *
     * @param string $location The location to look for
     *
     * @return Source|null
     */
    public function getSource(string $location): ?Source
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $location]);
        if ($source === null) {
//            $this->logger->error("No source found for $location");
            isset($this->io) && $this->io->error("No source found for $location");
        }//end if

        return $source;
    }//end getSource()

    /**
     * Get an entity by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($entity === null) {
//            $this->logger->error("No entity found for $reference");
            isset($this->io) && $this->io->error("No entity found for $reference");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Get a mapping by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Mapping|null
     */
    public function getMapping(string $reference): ?Mapping
    {
        $mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
        if ($mapping === null) {
//            $this->logger->error("No mapping found for $reference");
            isset($this->io) && $this->io->error("No mapping found for $reference");
        }//end if

        return $mapping;
    }//end getMapping()

    /**
     * This function create or get the component of the repository.
     *
     * @param ObjectEntity $repository
     *
     * @throws Exception
     *
     * @return ObjectEntity|null
     */
    public function connectComponent(ObjectEntity $repository): ?ObjectEntity
    {
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $components = $this->cacheService->searchObjects(null, ['url' => $repository->getSelf()], [$componentEntity->getId()->toString()])['results'];

        if ($components === []) {
            $component = new ObjectEntity($componentEntity);
            $component->hydrate([
                'name' => $repository->getValue('name'),
                'url'  => $repository,
            ]);
            $this->entityManager->persist($component);
        }//end if

        if (count($components) === 1) {
            $component = $this->entityManager->find('App:ObjectEntity', $components[0]['_self']['id']);
        }//end if

        if (isset($component) === true) {
            return $component;
        }//end if

        return null;
    }//end connectComponent()
}
