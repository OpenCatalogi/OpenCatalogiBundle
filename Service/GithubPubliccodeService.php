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
use Symfony\Component\Yaml\Yaml;

/**
 *  This class handles the interaction with developer.overheid.nl.
 */
class GithubPubliccodeService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private Entity $repositoryEntity;
    private Mapping $repositoryMapping;
    private MappingService $mappingService;

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

        return $this;
    }

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://api.github.com'])) {
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
     * Get the repositories mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoriesMapping(): ?Mapping
    {
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://api.github.com/search/code'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/search/code');
        }

        return $this->componentMapping;
    }

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://api.github.com/repositories'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/repositories');
        }

        return $this->componentMapping;
    }

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @return array
     */
    public function getRepositories(): array
    {
        $result = [];
        // Do we have a source
        if (!$source = $this->getSource()) {
            return $result;
        }

        // @TODO rows per page, pagination:
//        $repositories = $this->callService->getAllResults($source, '/search/code?q=publiccode+in:path+path:/+extension:yaml+extension:yml');
        
        // todo: only returns the first 10 items
        $response = $this->callService->call($source, '/search/code?q=publiccode+in:path+path:/+extension:yaml+extension:yml');

        $repositories = json_decode($response->getBody()->getContents(), true);

        isset($this->io) && $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories['items'] as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }
    
    /**
     * Get a repository trough the repositories of developer.overheid.nl/repositories/{id}.
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param string $id
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

        return $repository->toArray();
    }
    
    /**
     * @todo
     *
     * @param $repository
     * @return ?ObjectEntity
     */
    public function importPubliccodeRepository($repository): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a public code repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');
            
            return null;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a public code repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');
            
            return null;
        }
        if (!$mapping = $this->getRepositoriesMapping()) {
            isset($this->io) && $this->io->error('No RepositoriesMapping found when trying to import a public code repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');
            
            return null;
        }

        isset($this->io) && $this->io->comment('Mapping object '.$mapping);
        $repository = $this->mappingService->mapping($mapping, $repository['repository']['name']);
    
        isset($this->io) && $this->io->comment('Mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['repository']['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }
    
    /**
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param $repository
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
        if (!$mapping = $this->getRepositoryMapping()) {
            return null;
        }

        isset($this->io) && $this->io->comment('Mapping object '.$mapping);
        $repository = $this->mappingService->mapping($mapping, $repository['name']);
    
        isset($this->io) && $this->io->comment('Mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }
}
