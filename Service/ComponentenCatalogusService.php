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
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ComponentenCatalogusService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $developerOverheidService;

    /**
     * @param EntityManagerInterface   $entityManager            The Entity Manager Interface
     * @param CallService              $callService              The Call Service
     * @param SynchronizationService   $synchronizationService   The Synchronization Service
     * @param MappingService           $mappingService           The Mapping Service
     * @param DeveloperOverheidService $developerOverheidService The Developer Overheid Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        DeveloperOverheidService $developerOverheidService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->developerOverheidService = $developerOverheidService;
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
        $this->developerOverheidService->setStyle($io);
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
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @return array|null
     */
    public function getApplications(): ?array
    {
        $result = [];
        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');

        $applications = $this->callService->getAllResults($source, '/products');

        isset($this->io) && $this->io->success('Found '.count($applications).' applications');
        foreach ($applications as $application) {
            $result[] = $this->importApplication($application);
        }

        $this->entityManager->flush();

        return $result;
    }//end getApplications()

    /**
     * Get an application through the products of https://componentencatalogus.commonground.nl/api/products/{id}.
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getApplication(string $id): ?array
    {
        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');

        isset($this->io) && $this->io->success('Getting application '.$id);
        $response = $this->callService->call($source, '/products/'.$id);

        $application = json_decode($response->getBody()->getContents(), true);

        if (!$application) {
            isset($this->io) && $this->io->error('Could not find an application with id: '.$id.' and with source: '.$source->getName());

            return null;
        }
        $application = $this->importApplication($application);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found application with id: '.$id);

        return $application->toArray();
    }//end getApplication()

    /**
     * @todo
     *
     * @param $application
     *
     * @return ObjectEntity|null
     */
    public function importApplication($application): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');
        $applicationEntity = $this->getEntity('https://opencatalogi.nl/oc.application.schema.json');
        $mapping = $this->getMapping('https://componentencatalogus.commonground.nl/api/applications');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$application['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);

        isset($this->io) && $this->io->success('Checking application '.$application['name']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');

        if ($application['components']) {
            $components = [];
            foreach ($application['components'] as $component) {
                $componentObject = $this->importComponent($component);
                $components[] = $componentObject;
            }
            $applicationObject->setValue('components', $components);
        }

        $this->entityManager->persist($applicationObject);
        $this->entityManager->flush();

        return $applicationObject;
    }//end importApplication()

    /**
     * Get components through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @return array|null
     */
    public function getComponents(): ?array
    {
        $result = [];

        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');

        isset($this->io) && $this->io->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/components');

        isset($this->io) && $this->io->success('Found '.count($components).' components');
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }//end getComponents()

    /**
     * Get a component trough the components of https://componentencatalogus.commonground.nl/api/components/{id}.
     *
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param string $id
     *
     * @return array|null
     */
    public function getComponent(string $id): ?array
    {
        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');

        isset($this->io) && $this->io->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/components/'.$id);

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
     * @param array        $componentArray
     * @param ObjectEntity $componentObject
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        // if the component isn't already set to a repository create or get the repo and set it to the component url
        if (key_exists('url', $componentArray) &&
            key_exists('url', $componentArray['url']) &&
            key_exists('name', $componentArray['url'])) {
            if (!($repository = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $repositoryEntity, 'name' => $componentArray['url']['name']]))) {
                $repository = new ObjectEntity($repositoryEntity);
                $repository->hydrate([
                    'name' => $componentArray['url']['name'],
                    'url'  => $componentArray['url']['url'],
                ]);
            }
            $this->entityManager->persist($repository);
            if ($componentObject->getValue('url')) {
                // if the component is already set to a repository return the component object
                return $componentObject;
            }
            $componentObject->setValue('url', $repository);
        }

        return null;
    }//end importRepositoryThroughComponent()

    /**
     * @todo duplicate with DeveloperOverheidService ?
     *
     * @param $component
     *
     * @return ObjectEntity|null
     */
    public function importComponent($component): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->getSource('https://componentencatalogus.commonground.nl/api');
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $mapping = $this->getMapping('https://componentencatalogus.commonground.nl/api/components');

        // Handle sync
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$component['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking component '.$component['name']);

        // do the mapping of the component set two variables
        $component = $componentArray = $this->mappingService->mapping($mapping, $component);
        // unset component url before creating object, we don't want duplicate repositories
        unset($component['url']);
        if (key_exists('legal', $component) && key_exists('repoOwner', $component['legal'])) {
            unset($component['legal']['repoOwner']);
        }

        $synchronization = $this->synchronizationService->synchronize($synchronization, $component);
        $componentObject = $synchronization->getObject();

        $this->importRepositoryThroughComponent($componentArray, $componentObject);
        $this->developerOverheidService->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        $this->entityManager->persist($componentObject);
        $this->entityManager->flush();

        return $componentObject;
    }//end importComponent()
}
