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
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private ?Entity $applicationEntity;
    private ?Mapping $applicationMapping;
    private ?Entity $componentEntity;
    private ?Entity $legalEntity;
    private ?Mapping $componentMapping;
    private ?Entity $repositoryEntity;
    private MappingService $mappingService;
    private SymfonyStyle $io;
    private Entity $organisationEntity;
    private DeveloperOverheidService $developerOverheidService;

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
     * Get the componentencatalogus source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://componentencatalogus.commonground.nl/api'])) {
            isset($this->io) && $this->io->error('No source found for https://componentencatalogus.commonground.nl/api');

            return null;
        }

        return $this->source;
    }

    /**
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity
    {
        if (!$this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.application.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.application.schema.json');

            return null;
        }

        return $this->applicationEntity;
    }

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getOrganisationEntity(): ?Entity
    {
        if (!$this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');

            return null;
        }

        return $this->organisationEntity;
    }

    /**
     * Get the application mapping.
     *
     * @return ?Mapping
     */
    public function getApplicationMapping(): ?Mapping
    {
        if (!$this->applicationMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json'])) {
            isset($this->io) && $this->io->error('No mapping found for https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json');

            return null;
        }

        return $this->applicationMapping;
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

            return null;
        }

        return $this->repositoryEntity;
    }

    /**
     * Get the legal entity.
     *
     * @return ?Entity
     */
    public function getLegalEntity(): ?Entity
    {
        if (!$this->legalEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.legal.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://opencatalogi.nl/oc.legal.schema.json');

            return null;
        }

        return $this->legalEntity;
    }

    /**
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @return array|null
     */
    public function getApplications(): ?array
    {
        $result = [];
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get Applications');

            return null;
        }

        $applications = $this->callService->getAllResults($source, '/products');

        isset($this->io) && $this->io->success('Found '.count($applications).' applications');
        foreach ($applications as $application) {
            $result[] = $this->importApplication($application);
        }

        $this->entityManager->flush();

        return $result;
    }

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
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get an Application with id: '.$id);

            return null;
        }

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
    }

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
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to import an Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }
        if (!$applicationEntity = $this->getApplicationEntity()) {
            isset($this->io) && $this->io->error('No ApplicationEntity found when trying to import a Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }
        if (!$mapping = $this->getApplicationMapping()) {
            isset($this->io) && $this->io->error('No ApplicationMapping found when trying to import a Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$application['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$mapping);

        isset($this->io) && $this->io->success('Checking application '.$application['name']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        if (!$componentEntity = $this->getComponentEntity()) {
            isset($this->io) && $this->io->error('No componentEntity found when trying to import a Component');

            return null;
        }

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

            return null;
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
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json'])) {
            isset($this->io) && $this->io->error('No mapping found for https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json');

            return null;
        }

        return $this->componentMapping;
    }

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
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get Components');

            return null;
        }

        isset($this->io) && $this->io->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/components');

        isset($this->io) && $this->io->success('Found '.count($components).' components');
        foreach ($components as $component) {
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }

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
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get a Component with id: '.$id);

            return null;
        }

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
    }

    /**
     * @param array        $componentArray
     * @param ObjectEntity $componentObject
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        if (!$repositoryEntity = $this->getRepositoryEntity()) {
            isset($this->io) && $this->io->error('No RepositoryEntity found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }
        // if the component isn't already set to a repository create or get the repo and set it to the component url
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
    }

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
    }
}
