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
 *  This class handles the interaction with developer.overheid.nl.
 */
class DeveloperOverheidService
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
    private SynchronizationService $syncService;

    /**
     * @var Entity|null
     */
    private ?Entity $repositoryEntity;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @var Mapping|null
     */
    private ?Mapping $componentMapping;

    /**
     * @var Entity|null
     */
    private ?Entity $legalEntity;

    /**
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @param EntityManagerInterface $entityManager          EntityManagerInterface
     * @param CallService            $callService            CallService
     * @param SynchronizationService $synchronizationService SynchronizationService
     * @param MappingService         $mappingService         MappingService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->syncService = $synchronizationService;
        $this->mappingService = $mappingService;
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
        $this->syncService->setStyle($style);
        $this->mappingService->setStyle($style);

        return $this;
    }//end setStyle()

    /**
     * Get the developer overheid source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://developer.overheid.nl/api']);
        if ($this->source === false) {
            isset($this->style) && $this->style->error('No source found for https://developer.overheid.nl/api');

            return null;
        }

        return $this->source;
    }//end getSource()

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
     * Get repositories through the repositories of developer.overheid.nl/repositories.
     *
     * @todo duplicate with GithubPubliccodeService ?
     *
     * @return array|null
     */
    public function getRepositories(): ?array
    {
        $result = [];
        // Do we have a source
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get Repositories');

            return null;
        }

        $repositories = $this->callService->getAllResults($source, '/repositories');

        isset($this->style) && $this->style->success('Found '.count($repositories).' repositories');
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
        // Do we have a source.
        if ($source = $this->getSource() === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a Repository with id: '.$id);

            return null;
        }

        isset($this->style) && $this->style->success('Getting repository '.$id);
        $response = $this->callService->call($source, '/repositories/'.$id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === false) {
            isset($this->style) && $this->style->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName());

            return null;
        }
        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        isset($this->style) && $this->style->success('Found repository with id: '.$id);

        return $repository->getObject();
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
        // Do we have a source.
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }
        if ($repositoryEntity = $this->getRepositoryEntity() === false) {
            isset($this->style) && $this->style->error('No RepositoryEntity found when trying to import a Repository '.isset($repository['name']) ? $repository['name'] : '');

            return null;
        }

        isset($this->style) && $this->style->success('Checking repository '.$repository['name']);
        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        return $synchronization->getObject();
    }//end importRepository()

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
        $this->componentMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>'https://developer.overheid.nl/api/components']);
        if ($this->componentMapping === false) {
            isset($this->style) && $this->style->error('No mapping found for https://developer.overheid.nl/api/components');

            return null;
        }

        return $this->componentMapping;
    }//end getComponentMapping()

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

        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get Components');

            return null;
        }

        isset($this->style) && $this->style->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/apis');

        isset($this->style) && $this->style->success('Found '.count($components).' components');
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
        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a Component with id: '.$id);

            return null;
        }

        isset($this->style) && $this->style->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/apis/'.$id);

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
        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            isset($this->style) && $this->style->error('No source found when trying to get a Repository: ');

            return null;
        }

        $repositoryEntity = $this->getRepositoryEntity();
        if ($repositoryEntity === false) {
            isset($this->style) && $this->style->error('No repositoryEntity found when trying to import a Repository');

            return null;
        }

        // Handle sync
        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        isset($this->style) && $this->style->comment('Checking component '.$repository['name']);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

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
        $organisationEntity = $this->getOrganisationEntity();
        if ($organisationEntity === false) {
            isset($this->style) && $this->style->error('No organisationEntity found when trying to import an Organisation ');

            return null;
        }

        $legalEntity = $this->getLegalEntity();
        if ($legalEntity === false) {
            isset($this->style) && $this->style->error('No LegalEntity found when trying to import an Legal ');

            return null;
        }

        // if the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner
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
                    // if the component is already set to a repoOwner return the component object
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
        // Do we have a source
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

        // repoOwner

        $synchronization = $this->syncService->findSyncBySource($source, $componentEntity, $component['id']);

        isset($this->style) && $this->style->comment('Mapping object'.$component['service_name']);
        isset($this->style) && $this->style->comment('The mapping object '.$mapping);

        isset($this->style) && $this->style->comment('Checking component '.$component['service_name']);

        // do the mapping of the component set two variables
        $componentMapping = $componentArray = $this->mappingService->mapping($mapping, $component);
        // unset component url before creating object, we don't want duplicate repositories
        if (key_exists('legal', $componentMapping) && key_exists('repoOwner', $componentMapping['legal'])) {
            unset($componentMapping['legal']['repoOwner']);
        }

        $synchronization = $this->syncService->synchronize($synchronization, $componentMapping);
        $componentObject = $synchronization->getObject();

        $this->importLegalRepoOwnerThroughComponent($componentArray, $componentObject);

        if ($component['related_repositories']) {
            // only do someting with the first item in the array
            $repository = $component['related_repositories'][0];
            $repositoryObject = $this->handleRepositoryArray($repository);
            $repositoryObject->setValue('component', $componentObject);
            $componentObject->setValue('url', $repositoryObject);
        }

        $this->entityManager->persist($componentObject);

        return $componentObject;
    }//end importComponent()
}
