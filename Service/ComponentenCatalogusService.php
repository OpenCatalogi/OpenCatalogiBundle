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
     * @var Source
     */
    private Source $source;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * @var Entity|null
     */
    private ?Entity $applicationEntity;

    /**
     * @var Mapping|null
     */
    private ?Mapping $applicationMapping;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @var Entity|null
     */
    private ?Entity $legalEntity;

    /**
     * @var Mapping|null
     */
    private ?Mapping $componentMapping;

    /**
     * @var Entity|null
     */
    private ?Entity $repositoryEntity;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $developerOverheidService;

    /**
     * @param EntityManagerInterface   $entityManager            EntityManagerInterface
     * @param CallService              $callService              CallService
     * @param SynchronizationService   $synchronizationService   SynchronizationService
     * @param MappingService           $mappingService           MappingService
     * @param DeveloperOverheidService $developerOverheidService DeveloperOverheidService
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
    }//end __construct()

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style The symfony style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;
        $this->developerOverheidService->setStyle($style);
        $this->synchronizationService->setStyle($style);
        $this->mappingService->setStyle($style);

        return $this;
    }//end setStyle()

    /**
     * Get the componentencatalogus source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://componentencatalogus.commonground.nl/api']);
        if ($this->source === false) {
            isset($this->style) && $this->style->error('No source found for https://componentencatalogus.commonground.nl/api');

            return null;
        }

        return $this->source;
    }//end getSource()

    /**
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity
    {
        $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.application.schema.json']);
        if ($this->applicationEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.application.schema.json');

            return null;
        }

        return $this->applicationEntity;
    }//end getApplicationEntity()

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getOrganisationEntity(): ?Entity
    {
        $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if ($this->organisationEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');

            return null;
        }

        return $this->organisationEntity;
    }//end getOrganisationEntity()

    /**
     * Get the application mapping.
     *
     * @return ?Mapping
     */
    public function getApplicationMapping(): ?Mapping
    {
        $this->applicationMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://componentencatalogus.commonground.nl/api/applications']);
        if ($this->applicationMapping === false) {
            isset($this->style) && $this->style->error('No mapping found for https://componentencatalogus.commonground.nl/api/applications');

            return null;
        }

        return $this->applicationMapping;
    }//end getApplicationMapping()

    /**
     * Get the repository entity.
     *
     * @return ?Entity
     */
    public function getRepositoryEntity(): ?Entity
    {
        $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');

            return null;
        }

        return $this->repositoryEntity;
    }//end getRepositoryEntity()

    /**
     * Get the legal entity.
     *
     * @return ?Entity
     */
    public function getLegalEntity(): ?Entity
    {
        $this->legalEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.legal.schema.json']);
        if ($this->legalEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.legal.schema.json');

            return null;
        }

        return $this->legalEntity;
    }//end getLegalEntity()

    /**
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @return array|null
     */
    public function getApplications(): ?array
    {
        $result = [];
        // Do we have a source.
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get Applications');

            return null;
        }

        $applications = $this->callService->getAllResults($source, '/products');

        isset($this->style) && $this->style->success('Found '.count($applications).' applications');
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
        // Do we have a source.
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get an Application with id: '.$id);

            return null;
        }

        isset($this->style) && $this->style->success('Getting application '.$id);
        $response = $this->callService->call($source, '/products/'.$id);

        $application = json_decode($response->getBody()->getContents(), true);

        if ($application === false) {
            isset($this->style) && $this->style->error('Could not find an application with id: '.$id.' and with source: '.$source->getName());

            return null;
        }
        $application = $this->importApplication($application);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->style) && $this->style->success('Found application with id: '.$id);

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
        // Do we have a source.
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to import an Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }

        $applicationEntity = $this->getApplicationEntity();
        if ($applicationEntity === false) {
            isset($this->style) && $this->style->error('No ApplicationEntity found when trying to import a Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }

        $mapping = $this->getApplicationMapping();
        if ($mapping === false) {
            isset($this->style) && $this->style->error('No ApplicationMapping found when trying to import a Application '.isset($application['name']) ? $application['name'] : '');

            return null;
        }

        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);

        isset($this->style) && $this->style->comment('Mapping object'.$application['name']);
        isset($this->style) && $this->style->comment('The mapping object '.$mapping);

        isset($this->style) && $this->style->success('Checking application '.$application['name']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $application);

        $applicationObject = $synchronization->getObject();

        $componentEntity = $this->getComponentEntity();
        if ($componentEntity === false) {
            isset($this->style) && $this->style->error('No componentEntity found when trying to import a Component');

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
    }//end importApplication()

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://opencatalogi.nl/oc.component.schema.json']);
        if ($this->componentEntity === false) {
            isset($this->style) && $this->style->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');

            return null;
        }

        return $this->componentEntity;
    }//end getComponentEntity()

    /**
     * Get the component mapping.
     *
     * @return ?Mapping
     */
    public function getComponentMapping(): ?Mapping
    {
        $this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://componentencatalogus.commonground.nl/api/components']);
        if ($this->componentMapping === false) {
            isset($this->style) && $this->style->error('No mapping found for https://componentencatalogus.commonground.nl/api/components');

            return null;
        }

        return $this->componentMapping;
    }//end getComponentMapping()

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

        // Do we have a source.
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get Components');

            return null;
        }

        isset($this->style) && $this->style->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/components');

        isset($this->style) && $this->style->success('Found '.count($components).' components');
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
        // Do we have a source.
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a Component with id: '.$id);

            return null;
        }

        isset($this->style) && $this->style->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/components/'.$id);

        $component = json_decode($response->getBody()->getContents(), true);
        if ($component === false) {
            isset($this->style) && $this->style->error('Could not find a component with id: '.$id.' and with source: '.$source->getName());

            return null;
        }

        $component = $this->importComponent($component);
        if ($component === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->style) && $this->style->success('Found component with id: '.$id);

        return $component->toArray();
    }//end getCompone()

    /**
     * @param array        $componentArray
     * @param ObjectEntity $componentObject
     *
     * @return ObjectEntity|null
     */
    public function importRepositoryThroughComponent(array $componentArray, ObjectEntity $componentObject): ?ObjectEntity
    {
        $repositoryEntity = $this->getRepositoryEntity();
        if ($repositoryEntity === false) {
            isset($this->style) && $this->style->error('No RepositoryEntity found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }
        // if the component isn't already set to a repository create or get the repo and set it to the component url.
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
                // if the component is already set to a repository return the component object.
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
        // Do we have a source/
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }

        $componentEntity = $this->getComponentEntity();
        if ($componentEntity === false) {
            isset($this->style) && $this->style->error('No ComponentEntity found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }

        $mapping = $this->getComponentMapping();
        if ($mapping === false) {
            isset($this->style) && $this->style->error('No ComponentMapping found when trying to import a Component '.isset($component['name']) ? $component['name'] : '');

            return null;
        }

        // Handle sync.
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);

        isset($this->style) && $this->style->comment('Mapping object'.$component['name']);
        isset($this->style) && $this->style->comment('The mapping object '.$mapping);

        isset($this->style) && $this->style->comment('Checking component '.$component['name']);

        // do the mapping of the component set two variables.
        $component = $componentArray = $this->mappingService->mapping($mapping, $component);
        // unset component url before creating object, we don't want duplicate repositories.
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
