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
            $this->io->error('No source found for https://api.github.com');
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
            $this->io->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');
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
            $this->io->error('No mapping found for https://api.github.com/search/code');
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
            $this->io->error('No mapping found for https://api.github.com/repositories');
        }

        return $this->componentMapping;
    }

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @return array
     */
    public function getRepositories(): array
    {
        $result = [];
        // Dow e have a source
        if (!$source = $this->getSource()) {
            return $result;
        }

        // @TODO rows per page are 10, so i get only 10 results
        $response = $this->callService->call($source, '/search/code?q=publiccode+in:path+path:/+extension:yaml+extension:yml');

        $repositories = json_decode($response->getBody()->getContents(), true);

        $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories['items'] as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a repository trough the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @return array
     */
    public function getRepository(string $id)
    {

        // Dow e have a source
        if (!$source = $this->getSource()) {
            return;
        }

        $this->io->success('Getting repository '.$id);
        $response = $this->callService->call($source, '/repositories/'.$id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            $this->io->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName());

            return;
        }
        $repository = $this->importRepository($repository);

        $this->entityManager->flush();

        $this->io->success('Found repository with id: '.$id);

        return $repository->toArray();
    }

    /**
     * @return ObjectEntity
     */
    public function importPubliccodeRepository($repository)
    {

        // Dow e have a source
        if (!$source = $this->getSource()) {
            return;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            return;
        }
        if (!$mapping = $this->getRepositoriesMapping()) {
            return;
        }

        $this->io->comment('Mapping object '.$mapping);

        $this->io->comment('Checking repository '.$repository['repository']['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }

    /**
     * @return ObjectEntity
     */
    public function importRepository($repository)
    {

        // Dow e have a source
        if (!$source = $this->getSource()) {
            return;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            return;
        }
        if (!$mapping = $this->getRepositoryMapping()) {
            return;
        }

        $this->io->comment('Mapping object '.$mapping);

        $this->io->comment('Checking repository '.$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }
}
