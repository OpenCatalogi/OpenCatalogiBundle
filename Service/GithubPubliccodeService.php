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
use Symfony\Component\Yaml\Yaml;
use Psr\Log\LoggerInterface;

/**
 *  This class handles the interaction with github.com.
 */
class GithubPubliccodeService
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
    private ?Mapping $repositoryMapping;

    /**
     * @var Entity
     */
    private Entity $applicationEntity;

    /**
     * @var Mapping|null
     */
    private ?Mapping $repositoriesMapping;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Entity
     */
    private Entity $contractorsEntity;

    /**
     * @var Entity
     */
    private Entity $contactsEntity;

    /**
     * @var Entity
     */
    private Entity $maintenanceEntity;

    /**
     * @var Entity
     */
    private Entity $legalEntity;

    /**
     * @param EntityManagerInterface $entityManager          EntityManagerInterface
     * @param CallService            $callService            CallService
     * @param SynchronizationService $syncService SynchronizationService
     * @param MappingService         $mappingService         MappingService
     * @param LoggerInterface  $mappingLogger The logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->logger = $pluginLogger;
    }//end __construct()

    /**
     * Get the github api source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com']);
        if ($this->source === false) {
            $this->logger->error('No source found for https://api.github.com');

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
        $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if ($this->repositoryEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.repository.schema.json');

            return null;
        }

        return $this->repositoryEntity;
    }//end getRepositoryEntity()

    /**
     * Get the component entity.
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity
    {
        $this->componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.component.schema.json']);
        if ($this->componentEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.component.schema.json');

            return null;
        }

        return $this->componentEntity;
    }//end getComponentEntity()

    /**
     * Get the repositories mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoriesMapping(): ?Mapping
    {
        $this->repositoriesMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/search/code']);
        if ($this->repositoriesMapping === false) {
            $this->logger->error('No mapping found for https://api.github.com/search/code');

            return null;
        }

        return $this->repositoriesMapping;
    }//end getRepositoriesMapping()

    /**
     * Get the repository mapping.
     *
     * @return ?Mapping
     */
    public function getRepositoryMapping(): ?Mapping
    {
        $this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://api.github.com/repositories']);
        if ($this->repositoryMapping === false) {
            $this->logger->error('No mapping found for https://api.github.com/repositories');

            return null;
        }

        return $this->repositoryMapping;
    }//end getRepositoryMapping()

    /**
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity
    {
        $this->applicationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.application.schema.json']);
        if ($this->applicationEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.application.schema.json');
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
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.organisation.schema.json');

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
        $this->legalEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.legal.schema.json']);
        if ($this->legalEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.legal.schema.json');

            return null;
        }

        return $this->legalEntity;
    }//end getLegalEntity()

    /**
     * Get the maintenance entity.
     *
     * @return ?Entity
     */
    public function getMaintenanceEntity(): ?Entity
    {
        $this->maintenanceEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.maintenance.schema.json']);
        if ($this->maintenanceEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.maintenance.schema.json');

            return null;
        }

        return $this->maintenanceEntity;
    }//end getMaintenanceEntity()

    /**
     * Get the contractors entity.
     *
     * @return ?Entity
     */
    public function getContractorEntity(): ?Entity
    {
        $this->contractorsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contractor.schema.json']);
        if ($this->contractorsEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.contractor.schema.json');
        }

        return $this->contractorsEntity;
    }//end getContractorEntity()

    /**
     * Get the contact entity.
     *
     * @return ?Entity
     */
    public function getContactEntity(): ?Entity
    {
        $this->contactsEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.contact.schema.json']);
        if ($this->contactsEntity === false) {
            $this->logger->error('No entity found for https://opencatalogi.nl/oc.contact.schema.json');
        }

        return $this->contactsEntity;
    }//end getContactEntity()

    /**
     * Get the repository mapping.
     *
     * @return ?bool
     */
    public function checkGithubAuth(): ?bool
    {
        if ($this->source->getApiKey() === false) {
            $this->logger->error('No auth set for Source: GitHub API');

            return false;
        }

        return true;
    }//end checkGithubAuth()

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
        if ($source = $this->getSource() === false) {
            $this->logger->error('No source found when trying to get all Repositories');

            return null;
        }

        if ($this->checkGithubAuth() === false) {
            return null;
        }

        $config = ['query' => [
            'q' => 'publiccode in:path path:/ extension:yaml extension:yml',
        ]];

        // Find on publiccode.yaml
        $repositories = $this->callService->getAllResults($source, '/search/code', $config);

        $this->logger->info('Found '.count($repositories).' repositories');

        foreach ($repositories as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }//end getRepositories()

    /**
     * Get a repository trough the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param string $id The id
     *
     * @return array|null
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function getRepository(string $id): ?array
    {
        // Do we have a source
        if ($source = $this->getSource() === false) {
            $this->logger->error('No source found when trying to get a Repository with id: '.$id);

            return null;
        }

        if ($this->checkGithubAuth() === false) {
            return null;
        }

        $this->logger->info('Getting repository '.$id);

        $response = $this->callService->call($source, '/repositories/'.$id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === false) {
            $this->logger->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName());

            return null;
        }

        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->logger->info('Found repository with id: '.$id);

        return $repository->toArray();
    }//end getRepository()

    /**
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param array $repository The repository
     *
     * @return ?ObjectEntity
     */
    public function importPubliccodeRepository(array $repository): ?ObjectEntity
    {
        // Do we have a source.
        $source = $this->getSource();
        if ($source === false) {
            $this->logger->error('No source found when trying to import a Repository ');

            return null;
        }

        $repositoryEntity = $this->getRepositoryEntity();
        if ($repositoryEntity === false) {
            $this->logger->error('No RepositoryEntity found when trying to import a Repository ');

            return null;
        }

        $repositoriesMapping = $this->getRepositoriesMapping();
        if ($repositoriesMapping === false) {
            $this->logger->error('No repositoriesMapping found when trying to import a Repository ');

            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);

        $synchronization->setMapping($repositoriesMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);


        $this->logger->info('Repository synchronization created with id: '.$synchronization->getId()->toString(),
            [
                "Mapping object"=>$repository['repository']['name'],
                "The mapping object"=>$repositoriesMapping,
                "Checking repository"=>$repository['repository']['name']
            ]
        );

        return $synchronization->getObject();
    }//end importPubliccodeRepository()

    /**
     * @param array $repository The repository
     *
     * @return ObjectEntity|null
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function importRepository(array $repository): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->getSource();
        if ($source === false) {
            $this->logger->error('No source found when trying to import a Repository ');

            return null;
        }

        $repositoryEntity = $this->getRepositoryEntity();
        if ($repositoryEntity === false) {
            $this->logger->error('No RepositoryEntity found when trying to import a Repository ');

            return null;
        }

        $repositoryMapping = $this->getRepositoryMapping();
        if ($repositoryMapping === false) {
            $this->logger->error('No repositoriesMapping found when trying to import a Repository ');

            return null;
        }

        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['id']);

        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);

        $this->logger->info('Repository synchronization created with id: '.$synchronization->getId()->toString(),
            [
                "Mapping object"=>$repository['repository']['name'],
                "The mapping object"=>$repositoryMapping,
                "Checking repository"=>$repository['repository']['name']
            ]
        );

        return $synchronization->getObject();
    }//end importRepository()

    /**
     * @param array        $publiccode The public code
     * @param ObjectEntity $component  The Component object
     *
     * @return ObjectEntity|null
     */
    public function createApplicationSuite(array $publiccode, ObjectEntity $component): ?ObjectEntity
    {
        $applicationEntity = $this->getApplicationEntity();
        if ($applicationEntity === false) {
            $this->logger->error('No ApplicationEntity found when trying to import a Application');

            return null;
        }

        if (key_exists('applicationSuite', $publiccode)) {
            $application = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $applicationEntity, 'name' => $publiccode['applicationSuite']]);
            if ($application === false) {
                $application = new ObjectEntity($applicationEntity);
                $application->hydrate(
                    [
                        'name'       => $publiccode['applicationSuite'],
                        'components' => [$component],
                    ]
                );
            }
            $this->entityManager->persist($application);
            $component->setValue('applicationSuite', $application);
            $this->entityManager->persist($application);
            $this->entityManager->flush();
        }

        return null;
    }//end createApplicationSuite()

    /**
     * @param array        $publiccode      The publiccode
     * @param ObjectEntity $componentObject The component object
     *
     * @return ObjectEntity|null
     */
    public function createMainCopyrightOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getOrganisationEntity();
        if ($organisationEntity === false) {
            $this->logger->error('No OrganisationEntity found when trying to import a Organisation');

            return null;
        }

        $legalEntity = $this->getLegalEntity();
        if ($legalEntity === false) {
            $this->logger->error('No LegalEntity found when trying to import an Legal ');

            return null;
        }

        // if the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) === true &&
            key_exists('mainCopyrightOwner', $publiccode['legal']) === true &&
            key_exists('name', $publiccode['legal']['mainCopyrightOwner']) === true) {
            $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['mainCopyrightOwner']['name']]);
            if ($organisation === null) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(
                    [
                        'name' => $publiccode['legal']['mainCopyrightOwner']['name'],
                    ]
                );
            }
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal')) {
                if ($repoOwner = $legal->getValue('mainCopyrightOwner')) {
                    // if the component is already set to a repoOwner return the component object
                    return $componentObject;
                }

                $legal->setValue('mainCopyrightOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(
                [
                    'mainCopyrightOwner' => $organisation,
                ]
            );
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();
        }//end if

        return null;
    }//end createMainCopyrightOwner()

    /**
     * @param array        $publiccode      The publiccode array
     * @param ObjectEntity $componentObject The component object
     *
     * @return ObjectEntity|null
     */
    public function createRepoOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getOrganisationEntity();
        if ($organisationEntity === false) {
            $this->logger->error('No OrganisationEntity found when trying to import a Organisation');

            return null;
        }

        $legalEntity = $this->getLegalEntity();
        if ($legalEntity === false) {
            $this->logger->error('No LegalEntity found when trying to import an Legal ');

            return null;
        }

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) &&
            key_exists('repoOwner', $publiccode['legal']) &&
            key_exists('name', $publiccode['legal']['repoOwner'])) {
            $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['repoOwner']['name']]);
            if ($organisation === null) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate([
                    'name' => $publiccode['legal']['repoOwner']['name'],
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
    }//end createRepoOwner()

    /**
     * @param array        $publiccode      The Publiccode
     * @param ObjectEntity $componentObject The Component Object
     *
     * @return ObjectEntity|null
     */
    public function createContractors(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getMaintenanceEntity();
        if ($maintenanceEntity === false) {
            $this->logger->error('No MaintenanceEntity found when trying to import a Maintenance');

            return null;
        }

        $contractorsEntity = $this->getContractorEntity();
        if ($contractorsEntity === false) {
            $this->logger->error('No ContractorEntity found when trying to import an Contractor ');

            return null;
        }
        if (key_exists('maintenance', $publiccode) === true &&
            key_exists('contractors', $publiccode['maintenance']) === true) {
            $contractors = [];
            foreach ($publiccode['maintenance']['contractors'] as $contractor) {
                if (key_exists('name', $contractor)) {
                    $contractor = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contractorsEntity, 'name' => $contractor['name']]);
                    if ($contractor === null) {
                        $contractor = new ObjectEntity($contractorsEntity);
                        $contractor->hydrate(
                            [
                                'name' => $contractor['name'],
                            ]
                        );
                    }
                    $this->entityManager->persist($contractor);
                    $contractors[] = $contractor;
                }
            }

            $maintenance = $componentObject->getValue('maintenance');
            if ($maintenance === true) {
                if ($maintenance->getValue('contractors')) {
                    // if the component is already set to a contractors return the component object
                    return $componentObject;
                }

                $maintenance->setValue('contractors', $contractors);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }

            $maintenance = new ObjectEntity($contractorsEntity);
            $maintenance->hydrate(
                [
                    'contractors' => $contractors,
                ]
            );
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();
        }

        return null;
    }//end createContractors()

    /**
     * @param array        $publiccode      The publiccode array
     * @param ObjectEntity $componentObject The component object
     *
     * @return ObjectEntity|null
     */
    public function createContacts(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getMaintenanceEntity();
        if ($maintenanceEntity === false) {
            $this->logger->error('No MaintenanceEntity found when trying to import a Maintenance');

            return null;
        }

        $contactEntity = $this->getContactEntity();
        if ($contactEntity === false) {
            $this->logger->error('No ContactEntity found when trying to import an Contact ');

            return null;
        }
        if (key_exists('maintenance', $publiccode) === true &&
            key_exists('contacts', $publiccode['maintenance']) === true) {
            $contacts = [];
            foreach ($publiccode['maintenance']['contacts'] as $contact) {
                if (key_exists('name', $contact)) {
                    if (!($contact = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $this->contractorsEntity, 'name' => $contact['name']]))) {
                        $contact = new ObjectEntity($contactEntity);
                        $contact->hydrate(
                            [
                                'name' => $contact['name'],
                            ]
                        );
                    }
                    $this->entityManager->persist($contact);
                    $contacts[] = $contact;
                }
            }

            $maintenance = $componentObject->getValue('maintenance');
            if ($maintenance === true) {
                if ($maintenance->getValue('contacts') === true) {
                    // if the component is already set to a contractors return the component object
                    return $componentObject;
                }

                $maintenance->setValue('contacts', $contacts);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }

            $maintenance = new ObjectEntity($maintenanceEntity);
            $maintenance->hydrate(
                [
                    'contacts' => $contacts,
                ]
            );
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();
        }

        return null;
    }//end createContacts()

    /**
     * @param ObjectEntity $repository        The repository object
     * @param array        $publiccode        The publiccode array
     * @param Mapping      $repositoryMapping The mapping object
     *
     * @return ObjectEntity|null dataset at the end of the handler
     *
     * @todo
     */
    public function mapPubliccode(ObjectEntity $repository, array $publiccode, $repositoryMapping): ?ObjectEntity
    {
        $componentEntity = $this->getComponentEntity();
        if ($componentEntity === false) {
            $this->logger->error('No ComponentEntity found when trying to import a Component ');

            return null;
        }

        $component = $repository->getValue('component');
        if ($component === false) {
            $component = new ObjectEntity($componentEntity);
        }

        $this->logger->info('Mapping object'.key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name'), ["The mapping object"=>$repositoryMapping]);

        $componentArray = $this->mappingService->mapping($repositoryMapping, $publiccode);
        $component->hydrate($componentArray);
        // set the name
        $component->hydrate(
            [
            'name' => key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name'),
            ])
        ;

        $component = $this->createApplicationSuite($publiccode, $component);
        $component = $this->createMainCopyrightOwner($publiccode, $component);
        $component = $this->createRepoOwner($publiccode, $component);

        // @TODO these to functions aren't working
        // contracts and contacts are not set to the component
//        $component = $this->createContractors($publiccode, $component);
//        $component = $this->createContacts($publiccode, $component);

        $this->entityManager->flush();
        $this->entityManager->persist($component);
        $repository->setValue('component', $component);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;
    }//end mapPubliccode()

    /**
     * @param string $repositoryUrl The url of the repository
     * @param mixed $response The responce
     *
     * @return array|null
     */
    public function parsePubliccode(string $repositoryUrl, $response): ?array
    {
        $source = $this->getSource();
        if ($source === false) {
            $this->logger->error('No source found when trying to import a Repository ');

            return null;
        }

        $publiccode = $this->callService->decodeResponse($source, $response, 'application/json');

        if (is_array($publiccode) && key_exists('content', $publiccode)) {
            $publiccode = base64_decode($publiccode['content']);
        }

        // @TODO use decodeResponse from the callService
        try {
            $parsedPubliccode = Yaml::parse($publiccode);
        } catch (Exception $e) {
            $this->logger->error('Not able to parse '.$publiccode.' '.$e->getMessage());
        }

        if (isset($parsedPubliccode) === true) {
            $this->logger->error("Fetch and decode went succesfull for $repositoryUrl");

            return $parsedPubliccode;
        }

        return null;
    }//end parsePubliccode()
}
