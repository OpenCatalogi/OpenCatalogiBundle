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
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

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
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Yaml
     */
    private Yaml $yaml;

    /**
     * @param EntityManagerInterface $entityManager    The Entity Manager Interface.
     * @param CallService            $callService      The Call Service.
     * @param SynchronizationService $syncService      The Synchronization Service.
     * @param MappingService         $mappingService   The Mapping Service.
     * @param GithubApiService       $githubApiService The Github Api Service.
     * @param LoggerInterface        $logger           The Logger Interface.
     * @param Yaml                   $yaml             The Yaml.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        LoggerInterface $logger,
        Yaml $yaml
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->logger = $logger;
        $this->yaml = $yaml;
    }//end __construct()

    /**
     * Get a source by reference.
     *
     * @param string $location The location to look for.
     *
     * @return Source|null
     */
    public function getSource(string $location): ?Source
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $location]);
        if ($source === null) {
            $this->logger->error("No source found for $location".'.');
        }//end if

        return $source;
    }//end getSource()

    /**
     * Get an entity by reference.
     *
     * @param string $reference The reference to look for.
     *
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($entity === null) {
            $this->logger->error("No entity found for $reference.");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Get a mapping by reference.
     *
     * @param string $reference The reference to look for.
     *
     * @return Mapping|null
     */
    public function getMapping(string $reference): ?Mapping
    {
        $mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
        if ($mapping === null) {
            $this->logger->error("No mapping found for $reference.");
        }//end if

        return $mapping;
    }//end getMapping()

    /**
     * Check the auth of the github source.
     *
     * @param Source $source The given source to check the api key.
     *
     * @return bool|null If the api key is set or not.
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if ($source->getApiKey() === null) {
            $this->logger->error('No auth set for Source: '.$source->getName().'.');

            return false;
        }//end if

        return true;
    }//end checkGithubAuth()

    /**
     * @TODO Loop through all the pages of the github api.
     *
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @return array All imported repositories.
     */
    public function callSource(): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if ($this->checkGithubAuth($source) === false) {
            return null;
        }//end if

        $errorCount = 0;
        $pageCount = 1;
        $results = [];
        while ($errorCount < 5) {
            try {
                $url = 'https://api.github.com/search/code?q=publiccode+in:path+path:/+extension:yaml+extension:yml&page='.$pageCount;
                $pageCount++;

                // Setup the request, you can also use CURLOPT_URL.
                $ch = curl_init($url);

                // Returns the data/output as a string instead of raw data.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                // Set your auth headers.
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: '.$source->getApiKey(),
                    'User-Agent: smisidjan', // @TODO This is for everyone different set this to the github api sourc?
                ]);

                // Get stringified data/output. See CURLOPT_RETURNTRANSFER.
                $data = curl_exec($ch);

                // Get info about the request.
                $info = curl_getinfo($ch);
                // Close curl resource to free up system resources.
                curl_close($ch);

                $decodedResult = json_decode($data, true);

                if ($decodedResult === [] || !isset($decodedResult['results']) ||
                    $decodedResult['results'] === []) {
                    continue;
                }//end if

                $results[] = $decodedResult;
            } catch (\Exception $exception) {
                $errorCount++;
            }
        }

        return $results;
    }//end callSource()

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return array All imported repositories.
     **/
    public function getRepositories(): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if ($this->checkGithubAuth($source) === false) {
            return null;
        }//end if
        $result = [];

        $queryConfig['query'] = [
            'q' => 'publiccode in:path path:/ extension:yaml extension:yml',
        ];

        // Find on publiccode.yaml.
        $repositories = $this->callService->getAllResults($source, '/search/code', $queryConfig);

        $this->logger->debug('Found '.count($repositories).' repositories.');
        foreach ($repositories as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }//end getRepositories()

    /**
     * Get a repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param string $id The id of the repository from developer.overheid.nl.
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return array|null The imported repository as array.
     *
     * @todo Duplicate with DeveloperOverheidService?
     */
    public function getRepository(string $id): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        $this->logger->debug('Getting repository '.$id.'.');

        try {
            $response = $this->callService->call($source, '/repositories/'.$id.'.');
            $repository = json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $requestException) {
            $this->logger->error($requestException->getMessage());
        }

        if (isset($repository) === false) {
            $this->logger->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName().'.');

            return null;
        }//end if

        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        $this->logger->debug('Found repository with id: '.$id.'.');

        return $repository->toArray();
    }//end getRepository()

    /**
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param array $repository The repository array that will be imported.
     *
     * @throws GuzzleException|LoaderError|SyntaxError|Exception
     *
     * @return ObjectEntity|null The repository object.
     */
    public function importPubliccodeRepository(array $repository): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        $repositoriesMapping = $this->getMapping('https://api.github.com/search/code');

        $repository['repository']['name'] = str_replace('-', ' ', $repository['repository']['name']);

        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);

        $this->logger->debug('Mapping object'.$repository['repository']['name'].'.');
        $this->logger->debug('The mapping object '.$repositoriesMapping.'.');

        $this->logger->debug('Checking repository '.$repository['repository']['name'].'.');
        $synchronization->setMapping($repositoriesMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);
        $this->logger->debug('Repository synchronization created with id: '.$synchronization->getId()->toString().'.');

        $repository = $synchronization->getObject();

        $component = $this->githubApiService->connectComponent($repository);
        if ($component !== null) {
            $repository->setValue('component', $component);
            $this->entityManager->persist($repository);
            $this->entityManager->flush();
        }//end if

        return $repository;
    }//end importPubliccodeRepository()

    /**
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param array $repository The repository array that will be imported
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return ObjectEntity|null The repository object
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function importRepository(array $repository): ?ObjectEntity
    {
        // Do we have a source
        $source = $this->getSource('https://api.github.com');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $repositoryMapping = $this->getMapping('https://api.github.com/repositories');

        $repository['name'] = str_replace('-', ' ', $repository['name']);

        $synchronization = $this->syncService->findSyncBySource($source, $repositoryEntity, $repository['id']);

        $this->logger->debug('Mapping object'.$repository['name'].'.');
        $this->logger->debug('The mapping object '.$repositoryMapping.'.');
        $this->logger->debug('Checking repository '.$repository['name'].'.');
        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $repository);
        $this->logger->debug('Repository synchronization created with id: '.$synchronization->getId()->toString().'.');

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
     * @param array        $publiccode The publiccode array for updating the component object.
     * @param ObjectEntity $component  The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createApplicationSuite(array $publiccode, ObjectEntity $component): ?ObjectEntity
    {
        $applicationEntity = $this->getEntity('https://opencatalogi.nl/oc.application.schema.json');

        if (key_exists('applicationSuite', $publiccode) === true) {
            if ($application = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $applicationEntity, 'name' => $publiccode['applicationSuite']]) === false) {
                $application = new ObjectEntity($applicationEntity);
                $application->hydrate(['name' => $publiccode['applicationSuite'], 'components' => [$component]]);
            }//end if
            $this->entityManager->persist($application);
            $component->setValue('applicationSuite', $application);
            $this->entityManager->persist($application);
            $this->entityManager->flush();

            return $component;
        }//end if

        return null;
    }//end createApplicationSuite()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createMainCopyrightOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $legalEntity = $this->getEntity('https://opencatalogi.nl/oc.legal.schema.json');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) === true
            && key_exists('mainCopyrightOwner', $publiccode['legal']) === true
            && key_exists('name', $publiccode['legal']['mainCopyrightOwner']) === true
        ) {
            if ($organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['mainCopyrightOwner']['name']]) === false) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(['name' => $publiccode['legal']['mainCopyrightOwner']['name']]);
            }//end if
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal') === true) {
                if ($legal->getValue('mainCopyrightOwner') === true) {
                    // If the component is already set to a repoOwner return the component object.
                    return $componentObject;
                }//end if

                $legal->setValue('mainCopyrightOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(['mainCopyrightOwner' => $organisation]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createMainCopyrightOwner()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createRepoOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $legalEntity = $this->getEntity('https://opencatalogi.nl/oc.legal.schema.json');

        // If the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner.
        if (key_exists('legal', $publiccode) === true
            && key_exists('repoOwner', $publiccode['legal']) === true
            && key_exists('name', $publiccode['legal']['repoOwner']) === true
        ) {
            if (($organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['repoOwner']['name']])) === false) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate(['name' => $publiccode['legal']['repoOwner']['name']]);
            }//end if
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal') === true) {
                if ($legal->getValue('repoOwner') === true) {
                    // If the component is already set to a repoOwner return the component object.
                    return $componentObject;
                }//end if

                $legal->setValue('repoOwner', $organisation);
                $this->entityManager->persist($legal);

                $componentObject->setValue('legal', $legal);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $legal = new ObjectEntity($legalEntity);
            $legal->hydrate(['repoOwner' => $organisation]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createRepoOwner()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createContractors(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getEntity('https://opencatalogi.nl/oc.maintenance.schema.json');
        $contractorsEntity = $this->getEntity('https://opencatalogi.nl/oc.contractor.schema.json');

        if (key_exists('maintenance', $publiccode) === true
            && key_exists('contractors', $publiccode['maintenance']) === true
        ) {
            $contractors = [];
            foreach ($publiccode['maintenance']['contractors'] as $contractor) {
                if (key_exists('name', $contractor) === true) {
                    if (($contractor = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contractorsEntity, 'name' => $contractor['name']])) === false) {
                        $contractor = new ObjectEntity($contractorsEntity);
                        $contractor->hydrate(['name' => $contractor['name']]);
                    }//end if
                    $this->entityManager->persist($contractor);
                    $contractors[] = $contractor;
                }//end if
            }

            if ($maintenance = $componentObject->getValue('maintenance') === true) {
                if ($maintenance->getValue('contractors') === true) {
                    // If the component is already set to a contractors return the component object.
                    return $componentObject;
                }//end if

                $maintenance->setValue('contractors', $contractors);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $maintenance = new ObjectEntity($maintenanceEntity);
            $maintenance->hydrate(['contractors' => $contractors]);
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createContractors()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object.
     * @param ObjectEntity $componentObject The component object that is being updated.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object.
     */
    public function createContacts(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getEntity('https://opencatalogi.nl/oc.maintenance.schema.json');
        $contactEntity = $this->getEntity('https://opencatalogi.nl/oc.contact.schema.json');

        if (key_exists('maintenance', $publiccode) === true
            && key_exists('contacts', $publiccode['maintenance']) === true
        ) {
            $contacts = [];
            foreach ($publiccode['maintenance']['contacts'] as $contact) {
                if (key_exists('name', $contact) === true) {
                    if (($contact = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contactEntity, 'name' => $contact['name']])) === false) {
                        $contact = new ObjectEntity($contactEntity);
                        $contact->hydrate(['name' => $contact['name']]);
                    }//end if
                    $this->entityManager->persist($contact);
                    $contacts[] = $contact;
                }//end if
            }

            if ($maintenance = $componentObject->getValue('maintenance') === true) {
                if ($maintenance->getValue('contacts') === true) {
                    // If the component is already set to a contractors return the component object.
                    return $componentObject;
                }//end if

                $maintenance->setValue('contacts', $contacts);
                $this->entityManager->persist($maintenance);

                $componentObject->setValue('maintenance', $maintenance);
                $this->entityManager->persist($componentObject);
                $this->entityManager->flush();

                return $componentObject;
            }//end if

            $maintenance = new ObjectEntity($maintenanceEntity);
            $maintenance->hydrate(['contacts' => $contacts]);
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }

        return null;
    }//end createContacts()

    /**
     * This function maps the publiccode to a component.
     *
     * @param ObjectEntity $repository The repository object.
     * @param array        $publiccode The publiccode array for updating the component object.
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The repository with the updated component from the publiccode url.
     *
     * @todo
     */
    public function mapPubliccode(ObjectEntity $repository, array $publiccode): ?ObjectEntity
    {
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $repositoryMapping = $this->getMapping('https://api.github.com/publiccode/component');

        if (($component = $repository->getValue('component')) === false) {
            $component = new ObjectEntity($componentEntity);
        }//end if

        $this->logger->debug('Mapping object'.$repository->getValue('name'));
        $this->logger->debug('The mapping object '.$repositoryMapping);

        $componentArray = $this->mappingService->mapping($repositoryMapping, $publiccode);
        $component->hydrate($componentArray);
        // set the name
        $component->hydrate(['name' => key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name')]);

        $component = $this->createApplicationSuite($publiccode, $component);
        $component = $this->createMainCopyrightOwner($publiccode, $component);
        $component = $this->createRepoOwner($publiccode, $component);

        // @TODO These to functions aren't working.
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
     * This function parses the publiccode.
     *
     * @param string $repositoryUrl The repository url.
     * @param $response The response of the get publiccode call.
     *
     * @throws Exception
     *
     * @return array|null The parsed publiccode of the given repository.
     *
     * @todo
     */
    public function parsePubliccode(string $repositoryUrl, $response): ?array
    {
        $source = $this->getSource('https://api.github.com');
        $publiccode = $this->callService->decodeResponse($source, $response, 'application/json');

        if (is_array($publiccode) === true && key_exists('content', $publiccode) === true) {
            $publiccode = base64_decode($publiccode['content']);
        }//end if

        // @TODO Use decodeResponse from the callService.
        try {
            $parsedPubliccode = $this->yaml->parse($publiccode);
        } catch (Exception $e) {
            $this->logger->debug('Not able to parse '.$publiccode.' '.$e->getMessage().'.');
        }

        if (isset($parsedPubliccode) === true) {
            $this->logger->debug("Fetch and decode went succesfull for $repositoryUrl.");

            return $parsedPubliccode;
        }//end if

        return null;
    }//end parsePubliccode()
}
