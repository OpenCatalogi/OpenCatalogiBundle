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
    private ?Mapping $componentMapping;
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
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'c'])) {
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
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/repositories'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/repositories');
        }

        return $this->componentMapping;
    }

    /**
     * Makes sure this action has all the gateway objects it needs
     */
    private function getRequiredGatewayObjects()
    {
        // get github source
        if (!isset($this->githubApiSource) && !$this->githubApiSource = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Source: Github API');
            return false;
        }
        if (!isset($this->repositoryEntity) && !$this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for reference https://opencatalogi.nl/oc.repository.schema.json');
            return false;
        };
        if (!isset($this->organizationEntity) && !$this->organizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for reference https://opencatalogi.nl/oc.organisation.schema.json');
            return false;
        };

        if (!isset($this->repositoryMapping) && !$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/search/code'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a repository for reference https://api.github.com/search/code');
            return false;
        };

        if (!isset($this->componentMapping) && !$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/repositories'])) {
            isset($this->io) && $this->io->error('No mapping found for https://api.github.com/repositories');
            return false;
        }

        // check if github source has authkey
        if (!$this->githubApiSource->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: GitHub API');

            return false;
        }

        return true;
    }

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @return array
     */
    public function getRepositories(): array
    {
        $result = [];
        $safeToContinue = $this->getRequiredGatewayObjects();
        if (!$safeToContinue) {
            dump($safeToContinue);
            return [];
        }

        $config = [];
        $config['query'] = [
            'q' => 'publiccode in:path path:/ extension:yaml extension:yml'
        ];

        // Find on publiccode.yaml
        $repositories = $this->callService->getAllResults($this->githubApiSource, '/search/code', $config);

        isset($this->io) && $this->io->success('Found ' . count($repositories) . ' repositories');
        foreach ($repositories as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);


            $this->entityManager->flush();

            return $result;
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
            isset($this->io) && $this->io->error('No source found when trying to get a Repository with id: ' . $id);

            return null;
        }

        isset($this->io) && $this->io->success('Getting repository ' . $id);
        $response = $this->callService->call($source, '/repositories/' . $id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            isset($this->io) && $this->io->error('Could not find a repository with id: ' . $id . ' and with source: ' . $source->getName());

            return null;
        }
        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found repository with id: ' . $id);

        return $repository->toArray();
    }

    /**
     * @todo
     *
     * @param $repository
     *
     * @return ?ObjectEntity
     */
    public function importPubliccodeRepository($repository): ?ObjectEntity
    {
        isset($this->io) && $this->io->comment('Mapping object ' . $repository['repository']['name']);
        $mappedRepository = $this->mappingService->mapping($this->componentMapping, $repository);
        dump($mappedRepository);
        isset($this->io) && $this->io->comment('Checking repository ' . $repository['repository']['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource, $this->repositoryEntity, $repository['repository']['id']);
        $synchronization->setMapping($this->componentMapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $mappedRepository);

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
            isset($this->io) && $this->io->error('No source found when trying to import a Repository ' . isset($repository['name']) ? $repository['name'] : '');

            return null;
        }
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Repository ' . isset($repository['name']) ? $repository['name'] : '');

            return null;
        }
        if (!$mapping = $this->getRepositoryMapping()) {
            isset($this->io) && $this->io->error('No RepositoryMapping found when trying to import a Repository ' . isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        isset($this->io) && $this->io->comment('Mapping object ' . $mapping);
        $repository = $this->mappingService->mapping($mapping, $repository['name']);

        isset($this->io) && $this->io->comment('Mapping object ' . $mapping);

        isset($this->io) && $this->io->comment('Checking repository ' . $repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }
}
