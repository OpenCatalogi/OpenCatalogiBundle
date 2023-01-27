<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *  This class handles the interaction with developer.overheid.nl.
 */
class DeveloperOverheidService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private Entity $repositoryEntity;
    private Entity $componentEntity;
    private Mapping $componentMapping;
    private MappingService $mappingService;
    private SymfonyStyle $io;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
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
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);

        return $this;
    }

    /**
     * Get the developer overheid source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://developer.overheid.nl/api'])) {
            isset($this->io) && $this->io->error('No source found for https://developer.overheid.nl/api');
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
     * Get repositories through the repositories of developer.overheid.nl/repositories.
     *
     * @return array
     */
    public function getRepositories(): array
    {
        $result = [];
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get Repositories');

            return $result;
        }

        $repositories = $this->callService->getAllResults($source, '/repositories');

        isset($this->io) && $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories as $repository) {
            $result[] = $this->importRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getRepository(string $id): ?array
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with id: '.$id);

            return null;
        }

        isset($this->io) && $this->io->success('Getting repository '.$id);
        $response = $this->callService->call($source, '/repositories/'.$id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            isset($this->io) && $this->io->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName());

            return null;
        }
        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found repository with id: '.$id);

        return $repository->getObject();
    }

    /**
     * @todo
     *
     * @param $repository
     *
     * @return ObjectEntity|null
     */
    public function importRepository($repository): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        isset($this->io) && $this->io->success('Checking repository '.$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        if (!$this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.component.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');
        }

        return $this->componentEntity;
    }

    /**
     * Get the component mapping.
     *
     * @return ?Mapping
     */
    public function getComponentMapping(): ?Mapping
    {
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://developer.overheid.nl/api/components'])) {
            isset($this->io) && $this->io->error('No mapping found for https://developer.overheid.nl/api/components');
        }

        return $this->componentMapping;
    }

    /**
     * Get components through the components of developer.overheid.nl/apis.
     *
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @return array
     */
    public function getComponents(): array
    {
        $result = [];

        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get Components');

            return $result;
        }

        isset($this->io) && $this->io->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/apis');

        isset($this->io) && $this->io->success('Found '.count($components).' components');
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a component trough the components of developer.overheid.nl/apis/{id}.
     *
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getComponent(string $id): ?array
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Component with id: '.$id);

            return null;
        }

        isset($this->io) && $this->io->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/apis/'.$id);

        $component = json_decode($response->getBody()->getContents(), true);

        if (!$component) {
            isset($this->io) && $this->io->error('Could not find a component with id: '.$id.' and with source: '.$source->getName());

            return null;
        }

        $component = $this->importComponent($component);
        if ($component === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found component with id: '.$id);

        return $component->toArray();
    }

    /**
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @param $component
     *
     * @return ObjectEntity|null
     */
    public function importComponent($component): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }
        if (!$componentEntity = $this->getComponentEntity()) {
            isset($this->io) && $this->io->error('No ComponentEntity found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }
        if (!$mapping = $this->getComponentMapping()) {
            isset($this->io) && $this->io->error('No ComponentMapping found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }

        isset($this->io) && $this->io->debug('Mapping object'.$component['name']);
        $component = $this->mappingService->mapping($mapping, $component);

        isset($this->io) && $this->io->comment('Mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking component '.$component['service_name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $component);

        return $synchronization->getObject();
    }
}
