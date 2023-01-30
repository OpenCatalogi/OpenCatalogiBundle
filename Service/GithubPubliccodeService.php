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

        // if (!isset($this->organizationMapping) && !$this->organizationMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/search/code'])) {
        //     // @TODO Monolog ?
        //     isset($this->io) && $this->io->error('Could not find a repository for reference https://api.github.com/search/code');
        //     return false;
        // };

        if (!isset($this->repositoryMapping) && !$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/search/code'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a repository for reference https://api.github.com/search/code');
            return false;
        };

        if (!isset($this->repositoriesMapping) && !$this->repositoriesMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/repositories'])) {
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
            $result[] = $this->importPubliccodeRepository($repository, 'publiccode');
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
     * Maps a repository object and creates/updates a Synchronization
     *
     * @param $repository
     *
     * @return ?ObjectEntity
     */
    public function importPubliccodeRepository($repository): ?ObjectEntity
    {
        // Find or create existing sync
        $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource, $this->repositoryEntity, $repository['repository']['id']);
        isset($this->io) && $this->io->comment('Mapping repository object ' . $repository['repository']['name']);
        // Set mapping on sync object
        $synchronization->setMapping($this->repositoryMapping);
        // Map object and create/update it
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: ' . $synchronization->getId()->toString());

        return $synchronization->getObject();
    }

    /**
     * Turn an organisation array into an object we can handle
     *
     * @param array $repro
     * @param Mapping $mapping
     * 
     * @return ?ObjectEntity
     */
    public function handleOrganizationArray(array $organisation): ?ObjectEntity
    {
        // check for mapping
        if (!$this->organizationMapping) {
            $this->io->error('Organization mapping not set/given');

            return null;
        }
    
        // Find or create existing sync
        $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource, $this->organizationEntity, $organisation['id']);
        isset($this->io) && $this->io->comment('Mapping organisation object ' . $organisation['name']);
        // Set mapping on sync object
        $synchronization->setMapping($this->organizationMapping);
        // Map object and create/update it
        $synchronization = $this->synchronizationService->handleSync($synchronization, $organisation);
        isset($this->io) && $this->io->comment('Organisation synchronization created with id: ' . $synchronization->getId()->toString());

        $organisationObject = $synchronization->getObject();
        $organisation = $organisationObject->toArray();

        if (isset($organisation['repositories'])) {
            foreach ($organisation['repositories'] as $repository) {
                $repositoryObject = $this->importRepository($repository);
                // Organizations don't have repositories so we need to set to organization on the repo site and persist that @TODO i dont get this?
                // $repositoryObject->setValue('organization', $organisation);
                // $this->entityManager->persist($repository);
            }
        }

        return $organisationObject;
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
        $this->getRequiredGatewayObjects();

        // Find or create existing sync
        $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource, $this->repositoryEntity, $repository['id']);
        isset($this->io) && $this->io->comment('Mapping repository object ' . $repository['name']);
        // Set mapping on sync object
        $synchronization->setMapping($this->repositoriesMapping);
        // Map object and create/update it
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: ' . $synchronization->getId()->toString());

        $repositoryObject = $synchronization->getObject();
        $repository = $repositoryObject->toArray();

        dump($repository);

        // @TODO 
        if (isset($repository['organisation'])) {
            // @TODO create new function in this service that does the same as githubApiService->handleOrganizationArray
            $organisationObject = $this->handleOrganizationArray($repository['organisation']); 
            $repositoryObject->setValue('organization', $organisationObject->getId()->toString());
        }

        return $synchronization->getObject();
    }
}
