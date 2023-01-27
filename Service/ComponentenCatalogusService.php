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
        }

        return $this->applicationEntity;
    }

    /**
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @return array
     */
    public function getApplications(): array
    {
        $result = [];
        // Do we have a source
        if (!$source = $this->getSource()) {
            isset($this->io) && $this->io->error('No source found when trying to get Applications');

            return $result;
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
            isset($this->io) && $this->io->error('No ApplicationEntity found when trying to import a Repository '.isset($application['name']) ? $application['name'] : '');

            return null;
        }

        isset($this->io) && $this->io->success('Checking application '.$application['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $application);

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
        if (!$this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://componentencatalogus.commonground.nl/api/components'])) {
            isset($this->io) && $this->io->error('No mapping found for https://componentencatalogus.commonground.nl/api/components');
        }

        return $this->componentMapping;
    }

    /**
     * Get components through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @todo duplicate with DeveloperOverheidService ?
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

        isset($this->io) && $this->io->debug('Mapping object'.$component['name']);
        $component = $this->mappingService->mapping($mapping, $component);

        isset($this->io) && $this->io->comment('Mapping object '.$mapping);

        isset($this->io) && $this->io->comment('Checking component '.$component['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $component);

        return $synchronization->getObject();
    }
}
