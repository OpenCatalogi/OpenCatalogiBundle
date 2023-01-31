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
 *  This class handles the interaction with github.com.
 */
class GithubPubliccodeService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $githubApiSource;
    private SynchronizationService $synchronizationService;
    private ?Entity $repositoryEntity;
    private ?Entity $organizationEntity;
    private ?Mapping $repositoryMapping;
    private ?Mapping $organizationMapping;
    private ?Mapping $repositoriesMapping;
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

        return $this;
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
        if (!$this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json'])) {
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
        if (!$this->repositoriesMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/search/code'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/search/code');
        }

        return $this->repositoriesMapping;
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
     * Get the repository mapping.
     *
     * @return ?bool
     */
    public function checkGithubAuth(): ?bool
    {
        if (!$this->source->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: GitHub API');

            return false;
        }

        return true;
    }

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @return array
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function getRepositories(): ?array
    {
        $result = [];
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with id: '.$id);

            return null;
        }
        if (!$this->checkGithubAuth()) {
            return null;
        }

        $config['query'] = [
            'q' => 'publiccode in:path path:/ extension:yaml extension:yml',
        ];

        // Find on publiccode.yaml
        $repositories = $this->callService->getAllResults($source, '/search/code', $config);

        isset($this->io) && $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }
        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a repository trough the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @todo duplicate with DeveloperOverheidService ?
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

        if (!$this->checkGithubAuth()) {
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
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param $repository
     *
     * @return ?ObjectEntity
     */
    public function importPubliccodeRepository($repository): ?ObjectEntity
    {
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import a Repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');

            return null;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');

            return null;
        }
        if (!$repositoriesMapping = $this->getRepositoriesMapping()) {
            isset($this->io) && $this->io->error('No repositoriesMapping found when trying to import a Repository '.isset($repository['repository']['name']) ? $repository['repository']['name'] : '');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$repository['repository']['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$repositoriesMapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['repository']['name']);
        $synchronization->setMapping($repositoriesMapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        return $synchronization->getObject();
    }

    /**
     * @todo duplicate with DeveloperOverheidService ?
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
        if (!$repositoryMapping = $this->getRepositoryMapping()) {
            isset($this->io) && $this->io->error('No repositoriesMapping found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        if (!isset($repository['id'])) {
            isset($this->io) && $this->io->error('No id set on repository, returning null and continuing');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$repository['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$repositoryMapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['name']);
        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        return $synchronization->getObject();
    }
}
