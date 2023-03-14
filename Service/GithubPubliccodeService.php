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
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param CallService            $callService            The Call Service
     * @param SynchronizationService $synchronizationService The Synchronization Service
     * @param MappingService         $mappingService         The Mapping Service
     * @param GithubApiService       $githubApiService       The Github Api Service
     * @param LoggerInterface        $pluginLogger           The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        GithubApiService $githubApiService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->githubApiService = $githubApiService;
        $this->logger = $pluginLogger;
    }//end __construct()

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
     * Check the auth of the github source.
     *
     * @param Source $source The given source to check the api key
     *
     * @return bool|null If the api key is set or not
     */
    public function checkGithubAuth(Source $source): ?bool
    {
        if (!$source->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: '.$source->getName());

            return false;
        }//end if

        return true;
    }//end checkGithubAuth()

    /**
     * Get repositories through the repositories of https://api.github.com/search/code
     * with query ?q=publiccode+in:path+path:/+extension:yaml+extension:yml.
     *
     * @return array All imported repositories
     */
    public function callSource(): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        $errorCount = 0;
        $pageCount = 1;
        $results = [];
        while ($errorCount < 5) {
            try {
                $url = 'https://api.github.com/search/code?q=publiccode+in:path+path:/+extension:yaml+extension:yml&page='.$pageCount;
                $pageCount++;

                //setup the request, you can also use CURLOPT_URL
                $ch = curl_init($url);

                // Returns the data/output as a string instead of raw data
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                //Set your auth headers
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: '.$source->getApiKey(),
                    'User-Agent: smisidjan', // @TODO this is for everyone different set this to the github api sourc?
                ]);

                // get stringified data/output. See CURLOPT_RETURNTRANSFER
                $data = curl_exec($ch);

                // get info about the request
                $info = curl_getinfo($ch);
                // close curl resource to free up system resources
                curl_close($ch);

                $decodedResult = json_decode($data, true);

                if ($decodedResult === [] || !isset($decodedResult['results']) ||
                    $decodedResult['results'] === []) {
                    var_dump($decodedResult);
                    continue;
                }

                var_dump($decodedResult);

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
     * @return array All imported repositories
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function getRepositories(): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        $result = [];

        $config['query'] = [
            'q' => 'publiccode in:path path:/ extension:yaml extension:yml',
        ];

        // Find on publiccode.yaml
        $repositories = $this->callService->getAllResults($source, '/search/code', $config);

//        $data = $this->callSource();
//        var_dump($data);
//        $repositories = $data['items'];

        isset($this->io) && $this->io->success('Found '.count($repositories).' repositories');
        foreach ($repositories as $repository) {
            $result[] = $this->importPubliccodeRepository($repository);
        }
        $this->entityManager->flush();

        return $result;
    }//end getRepositories()

    /**
     * Get a repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param string $id The id of the repository from developer.overheid.nl
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return array|null The imported repository as array
     *
     * @todo duplicate with DeveloperOverheidService ?
     */
    public function getRepository(string $id): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        isset($this->io) && $this->io->success('Getting repository '.$id);
        $response = $this->callService->call($source, '/repositories/'.$id);

        $repository = json_decode($response->getBody()->getContents(), true);

        if (!$repository) {
            isset($this->io) && $this->io->error('Could not find a repository with id: '.$id.' and with source: '.$source->getName());

            return null;
        }//end if
        $repository = $this->importRepository($repository);
        if ($repository === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found repository with id: '.$id);

        return $repository->toArray();
    }//end getRepository()

    /**
     * Maps a repository object and creates/updates a Synchronization.
     *
     * @param array $repository The repository array that will be imported
     *
     * @throws GuzzleException|LoaderError|SyntaxError|Exception
     *
     * @return ObjectEntity|null The repository object
     */
    public function importPubliccodeRepository(array $repository): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');
        $repositoriesMapping = $this->getMapping('https://api.github.com/search/code');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['repository']['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$repository['repository']['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$repositoriesMapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['repository']['name']);
        $synchronization->setMapping($repositoriesMapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

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

        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$repository['name']);
        isset($this->io) && $this->io->comment('The mapping object '.$repositoryMapping);

        isset($this->io) && $this->io->comment('Checking repository '.$repository['name']);
        $synchronization->setMapping($repositoryMapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $repository);
        isset($this->io) && $this->io->comment('Repository synchronization created with id: '.$synchronization->getId()->toString());

        $repositoryObject = $synchronization->getObject();

        if (!($component = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $componentEntity, 'name' => $repository['name']]))) {
            $component = new ObjectEntity($componentEntity);
            $component->hydrate([
                'name' => $repository['name'],
                'url'  => $repositoryObject,
            ]);
            $this->entityManager->persist($component);
        }//end if
        $repositoryObject->setValue('component', $component);
        $this->entityManager->persist($repositoryObject);
        $this->entityManager->flush();

        return $repositoryObject;
    }//end importRepository()

    /**
     * @param array        $publiccode The publiccode array for updating the component object
     * @param ObjectEntity $component  The component object that is being updated
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createApplicationSuite(array $publiccode, ObjectEntity $component): ?ObjectEntity
    {
        $applicationEntity = $this->getEntity('https://opencatalogi.nl/oc.application.schema.json');

        if (key_exists('applicationSuite', $publiccode)) {
            if (!$application = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $applicationEntity, 'name' => $publiccode['applicationSuite']])) {
                $application = new ObjectEntity($applicationEntity);
                $application->hydrate([
                    'name'       => $publiccode['applicationSuite'],
                    'components' => [$component],
                ]);
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
     * @param array        $publiccode      The publiccode array for updating the component object
     * @param ObjectEntity $componentObject The component object that is being updated
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createMainCopyrightOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $legalEntity = $this->getEntity('https://opencatalogi.nl/oc.legal.schema.json');

        // if the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner
        if (key_exists('legal', $publiccode) &&
            key_exists('mainCopyrightOwner', $publiccode['legal']) &&
            key_exists('name', $publiccode['legal']['mainCopyrightOwner'])) {
            if (!($organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['mainCopyrightOwner']['name']]))) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate([
                    'name' => $publiccode['legal']['mainCopyrightOwner']['name'],
                ]);
            }//end if
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal')) {
                if ($repoOwner = $legal->getValue('mainCopyrightOwner')) {
                    // if the component is already set to a repoOwner return the component object
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
            $legal->hydrate([
                'mainCopyrightOwner' => $organisation,
            ]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createMainCopyrightOwner()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object
     * @param ObjectEntity $componentObject The component object that is being updated
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createRepoOwner(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $legalEntity = $this->getEntity('https://opencatalogi.nl/oc.legal.schema.json');

        // if the component isn't already set to a organisation (legal.repoOwner) create or get the org and set it to the component legal repoOwner
        if (key_exists('legal', $publiccode) &&
            key_exists('repoOwner', $publiccode['legal']) &&
            key_exists('name', $publiccode['legal']['repoOwner'])) {
            if (!($organisation = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $organisationEntity, 'name' => $publiccode['legal']['repoOwner']['name']]))) {
                $organisation = new ObjectEntity($organisationEntity);
                $organisation->hydrate([
                    'name' => $publiccode['legal']['repoOwner']['name'],
                ]);
            }//end if
            $this->entityManager->persist($organisation);

            if ($legal = $componentObject->getValue('legal')) {
                if ($repoOwner = $legal->getValue('repoOwner')) {
                    // if the component is already set to a repoOwner return the component object
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
            $legal->hydrate([
                'repoOwner' => $organisation,
            ]);
            $this->entityManager->persist($legal);
            $componentObject->setValue('legal', $legal);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createRepoOwner()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object
     * @param ObjectEntity $componentObject The component object that is being updated
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createContractors(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getEntity('https://opencatalogi.nl/oc.maintenance.schema.json');
        $contractorsEntity = $this->getEntity('https://opencatalogi.nl/oc.contractor.schema.json');

        if (key_exists('maintenance', $publiccode) &&
            key_exists('contractors', $publiccode['maintenance'])) {
            $contractors = [];
            foreach ($publiccode['maintenance']['contractors'] as $contractor) {
                if (key_exists('name', $contractor)) {
                    if (!($contractor = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contractorsEntity, 'name' => $contractor['name']]))) {
                        $contractor = new ObjectEntity($contractorsEntity);
                        $contractor->hydrate([
                            'name' => $contractor['name'],
                        ]);
                    }//end if
                    $this->entityManager->persist($contractor);
                    $contractors[] = $contractor;
                }//end if
            }

            if ($maintenance = $componentObject->getValue('maintenance')) {
                if ($maintenance->getValue('contractors')) {
                    // if the component is already set to a contractors return the component object
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
            $maintenance->hydrate([
                'contractors' => $contractors,
            ]);
            $this->entityManager->persist($maintenance);
            $componentObject->setValue('maintenance', $maintenance);
            $this->entityManager->persist($componentObject);
            $this->entityManager->flush();

            return $componentObject;
        }//end if

        return null;
    }//end createContractors()

    /**
     * @param array        $publiccode      The publiccode array for updating the component object
     * @param ObjectEntity $componentObject The component object that is being updated
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The updated component object
     */
    public function createContacts(array $publiccode, ObjectEntity $componentObject): ?ObjectEntity
    {
        $maintenanceEntity = $this->getEntity('https://opencatalogi.nl/oc.maintenance.schema.json');
        $contactEntity = $this->getEntity('https://opencatalogi.nl/oc.contact.schema.json');

        if (key_exists('maintenance', $publiccode) &&
            key_exists('contacts', $publiccode['maintenance'])) {
            $contacts = [];
            foreach ($publiccode['maintenance']['contacts'] as $contact) {
                if (key_exists('name', $contact)) {
                    if (!($contact = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['entity' => $contactEntity, 'name' => $contact['name']]))) {
                        $contact = new ObjectEntity($contactEntity);
                        $contact->hydrate([
                            'name' => $contact['name'],
                        ]);
                    }//end if
                    $this->entityManager->persist($contact);
                    $contacts[] = $contact;
                }//end if
            }

            if ($maintenance = $componentObject->getValue('maintenance')) {
                if ($maintenance->getValue('contacts')) {
                    // if the component is already set to a contractors return the component object
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
            $maintenance->hydrate([
                'contacts' => $contacts,
            ]);
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
     * @param ObjectEntity $repository The repository object
     * @param array        $publiccode The publiccode array for updating the component object
     *
     * @throws Exception
     *
     * @return ObjectEntity|null The repository with the updated component from the publiccode url
     *
     * @todo
     */
    public function mapPubliccode(ObjectEntity $repository, array $publiccode): ?ObjectEntity
    {
        $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');
        $repositoryMapping = $this->getMapping('https://api.github.com/publiccode/component');

        if (!$component = $repository->getValue('component')) {
            $component = new ObjectEntity($componentEntity);
        }//end if

        isset($this->io) && $this->io->comment('Mapping object'.key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name'));
        isset($this->io) && $this->io->comment('The mapping object '.$repositoryMapping);

        $componentArray = $this->mappingService->mapping($repositoryMapping, $publiccode);
        $component->hydrate($componentArray);
        // set the name
        $component->hydrate([
            'name' => key_exists('name', $publiccode) ? $publiccode['name'] : $repository->getValue('name'),
        ]);

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
     * This function parses the publiccode.
     *
     * @param string $repositoryUrl The repository url
     * @param $response The response of the get publiccode call
     *
     * @throws Exception
     *
     * @return array|null The parsed publiccode of the given repository
     *
     * @todo
     */
    public function parsePubliccode(string $repositoryUrl, $response): ?array
    {
        $source = $this->getSource('https://api.github.com');
        $publiccode = $this->callService->decodeResponse($source, $response, 'application/json');

        if (is_array($publiccode) && key_exists('content', $publiccode)) {
            $publiccode = base64_decode($publiccode['content']);
        }//end if

        // @TODO use decodeResponse from the callService
        try {
            $parsedPubliccode = Yaml::parse($publiccode);
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Not able to parse '.$publiccode.' '.$e->getMessage());
        }

        if (isset($parsedPubliccode)) {
            isset($this->io) && $this->io->success("Fetch and decode went succesfull for $repositoryUrl");

            return $parsedPubliccode;
        }//end if

        return null;
    }//end parsePubliccode()
}
