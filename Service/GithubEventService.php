<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Mapping;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;
use App\Service\SynchronizationService;
use App\Service\HandlerService;
use CommonGateway\CoreBundle\Service\MappingService;

class GithubEventService
{
    private EntityManagerInterface $entityManager;
    private array $configuration;
    private array $data;
    private ?Entity $repositoryEntity;
    private ?Mapping $repositoriesMapping;
    private ?Source $source;
    private SynchronizationService $synchronizationService;
    private HandlerService $handlerService;
    private MappingService $mappingService;

    public function __construct(
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        HandlerService $handlerService,
        MappingService $mappingService
    ) {
        $this->entityManager = $entityManager;
        $this->configuration = [];
        $this->data = [];
        $this->synchronizationService = $synchronizationService;
        $this->handlerService = $handlerService;
        $this->mappingService = $mappingService;
    }

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            isset($this->io) && $this->io->error('No source found for https://api.github.com');
        }

        return $this->source;
    }

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getRepositoryEntity(): ?Entity
    {
        if (!$this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.repository.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');
        }

        return $this->repositoryEntity;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        if (!$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/repositories'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/repositories');
        }

        return $this->repositoryMapping;
    }

    /**
     * @param ?array $data          data set at the start of the handler
     * @param ?array $configuration configuration of the action
     *
     * @return array|null
     */
    public function updateRepositoryWithEventResponse(?array $data = [], ?array $configuration = []): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $request = $this->data['parameters'];
        if (!$githubEvent = json_decode($request->get('payload'), true)) {
            $githubEvent = $this->data['request'];
        }

        $repositoryName = $githubEvent['repository']['name'];

        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a Repository '.isset($repositoryName) ? $repositoryName : '');

            return null;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository '.isset($repositoryName) ? $repositoryName : '');

            return null;
        }
        if (!$mapping = $this->getRepositoryMapping()) {
            isset($this->io) && $this->io->error('No RepositoryMapping found when trying to import a Repository '.isset($repositoryName) ? $repositoryName : '');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $githubEvent['repository']['id']);

        isset($this->io) && $this->io->comment('Mapping object '.$repositoryName);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);
        isset($this->io) && $this->io->comment('Checking repository '.$repositoryName);

        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $githubEvent['repository']);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        $repository = $synchronization->getObject();

        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        $this->data['response'] = $repository->toArray();

        return $this->data;
    }
}
