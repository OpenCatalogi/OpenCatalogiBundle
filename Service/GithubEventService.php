<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Mapping;
use App\Exception\GatewayException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;
use Symfony\Component\HttpFoundation\Response;
use App\Service\SynchronizationService;
use App\Service\HandlerService;
use CommonGateway\CoreBundle\Service\MappingService;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

class GithubEventService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;
    private ?Entity $repositoryEntity;
    private ?Mapping $repositoriesMapping;
    private ?Source $source;
    private SynchronizationService $synchronizationService;
    private HandlerService $handlerService;
    private MappingService $mappingService;

    /**
     * @param EntityManagerInterface $entityManager The Entity Manager Interface
     * @param SynchronizationService $synchronizationService The Synchronization Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService
    ) {
        $this->entityManager = $entityManager;
        $this->configuration = [];
        $this->data = [];
        $this->synchronizationService = $synchronizationService;
    }

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
     * This function creates/updates the repository with the github event response
     *
     * @param ?array $data data set at the start of the handler
     * @param ?array $configuration configuration of the action
     *
     * @return array|null The data with the repository in the response array
     * @throws GuzzleException|GatewayException|CacheException|InvalidArgumentException|ComponentException|LoaderError|SyntaxError
     */
    public function updateRepositoryWithEventResponse(?array $data = [], ?array $configuration = []): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if (key_exists('payload', $this->data)) {
            $githubEvent = $this->data['payload'];
        } else {
            $githubEvent = $this->data['body'];
        }

        $repositoryName = $githubEvent['repository']['name'];

        $source = $this->getSource('https://api.github.com');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $mapping = $this->getMapping('https://api.github.com/repositories');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $githubEvent['repository']['id']);

        isset($this->io) && $this->io->comment('Mapping object '.$repositoryName);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);
        isset($this->io) && $this->io->comment('Checking repository '.$repositoryName);

        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $githubEvent['repository']);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        $repository = $synchronization->getObject();

        if (!($component = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $componentEntity, 'name' => $repositoryName]))) {
            $component = new ObjectEntity($componentEntity);
            $component->hydrate([
                'name' => $repositoryName,
                'url'  => $repository,
            ]);
            $this->entityManager->persist($component);
        }
        $repository->setValue('component', $component);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        $this->data['response'] = new Response(json_encode($repository->toArray()), 200);

        return $this->data;
    }
}
