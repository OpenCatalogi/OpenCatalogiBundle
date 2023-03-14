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
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *  This class handles the interaction with developer.overheid.nl.
 */
class DeveloperOverheidService
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param CallService            $callService            The Call Service
     * @param CacheService           $cacheService           The Cache Service
     * @param SynchronizationService $synchronizationService The Synchronization Service
     * @param MappingService         $mappingService         The Mapping Service
     * @param GithubApiService       $githubApiService       The Github Api Service
     * @param LoggerInterface        $pluginLogger           The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        CacheService $cacheService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->cacheService = $cacheService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->logger = $pluginLogger;
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
     * Get repositories through the repositories of developer.overheid.nl/repositories.
     *
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @return array|null
     */
    public function getRepositories(): ?array
    {
        $result = [];
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');

        $repositories = $this->callService->getAllResults($source, '/repositories');

        isset($this->io) && $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories as $repository) {
            $result[] = $this->importRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }//end getRepositories()

    /**
     * Get a repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getRepository(string $id): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');

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
    }//end getRepository()

    /**
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @param $repository
     *
     * @return ObjectEntity|null
     */
    public function importRepository($repository): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');

        isset($this->io) && $this->io->success('Checking repository '.$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $repository);

        $repositoryObject = $synchronization->getObject();

        $component = $this->githubApiService->connectComponent($repositoryObject);
        if ($component !== null) {
            $repositoryObject->setValue('component', $component);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->flush();
        }//end if

        return $repositoryObject;
    }//end importRepository()

    /**
     * Get components through the components of developer.overheid.nl/apis.
     *
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @return array|null
     */
    public function getComponents(): ?array
    {
        $result = [];

        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');

        isset($this->io) && $this->io->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/apis');

        isset($this->io) && $this->io->success('Found '.count($components).' components');
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }//end getComponents()

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
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');

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
    }//end getComponent()

    /**
     * Turn a repo array into an object we can handle.
     *
     * @param array $repository
     *
     * @return ?ObjectEntity
     */
    public function handleRepositoryArray(array $repository): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');

        // Handle sync.
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        isset($this->io) && $this->io->comment('Checking component '.$repository['name']);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $repository);

        return $synchronization->getObject();
    }//end handleRepositoryArray()

    /**
     * @param array        $componentArray
     * @param ObjectEntity $componentObject
     *
     * @return ObjectEntity|null
     */
    public function importLegalRepoOwnerThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $legalEntity = $this->getEntity('https://opencatalogi.nl/oc.legal.schema.json');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $componentArray) &&
            key_exists('repoOwner', $componentArray['legal']) &&
            key_exists('name', $componentArray['legal']['repoOwner'])) {
            if (!($organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $componentArray['legal']['repoOwner']['name']]))) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate([
                    'name'     => $componentArray['legal']['repoOwner']['name'],
                    'email'    => key_exists('email', $componentArray['legal']['repoOwner']) ? $componentArray['legal']['repoOwner']['email'] : null,
                    'phone'    => key_exists('phone', $componentArray['legal']['repoOwner']) ? $componentArray['legal']['repoOwner']['phone'] : null,
                    'website'  => key_exists('website', $componentArray['legal']['repoOwner']) ? $componentArray['legal']['repoOwner']['website'] : null,
                    'type'     => key_exists('type', $componentArray['legal']['repoOwner']) ? $componentArray['legal']['repoOwner']['type'] : null,
                ]);
            }
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal')) {
                if ($repoOwner = $legal->getValue('repoOwner')) {
                    // If the component is already set to a repoOwner return the component object.
                    return $componentObject;
                }

                $legal->setValue('repoOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate([
                'repoOwner' => $organisation,
            ]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();
        }

        return null;
    }//end importLegalRepoOwnerThroughComponent()

    /**
     * @todo duplicate with ComponentenCatalogusService ?
     *
     * @param $component
     *
     * @return ObjectEntity|null
     */
    public function importComponent($component): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://developer.overheid.nl/api');
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $mapping = $this->getMapping('https://developer.overheid.nl/api/components');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$component['service_name']);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking component '.$component['service_name']);

        // Do the mapping of the component set two variables.
        $componentMapping = $componentArray = $this->mappingService->mapping($mapping, $component);
        // Unset component legal before creating object, we don't want duplicate organisations.
        if (key_exists('legal', $componentMapping) && key_exists('repoOwner', $componentMapping['legal'])) {
            unset($componentMapping['legal']['repoOwner']);
        }

        $synchronization = $this->synchronizationService->synchronize($synchronization, $componentMapping);
        $componentObject = $synchronization->getObject();

        $this->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        if ($component['related_repositories']) {
            $repository = $component['related_repositories'][0];
            $repositoryObject = $this->handleRepositoryArray($repository);
            $repositoryObject->setValue('component', $componentObject);
            $componentObject->setValue('url', $repositoryObject);
        }

        $this->entityManager->persist($componentObject);

        return $componentObject;
    }//end importComponent()
}//end class
