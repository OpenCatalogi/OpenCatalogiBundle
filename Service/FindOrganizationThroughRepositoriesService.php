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
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Psr\Log\LoggerInterface;

/**
 * Loops through repositories (https://opencatalogi.nl/oc.repository.schema.json) and updates it with fetched organization info.
 */
class FindOrganizationThroughRepositoriesService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $githubPubliccodeService;

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
     * @var Entity
     */
    private Entity $organisationEntity;

    /**
     * @var Mapping
     */
    private Mapping $organisationMapping;

    /**
     * @var Entity
     */
    private Entity $repositoryEntity;

    /**
     * @var Mapping
     */
    private Mapping $repositoryMapping;

    /**
     * @var Source
     */
    private Source $githubApi;

    /**
     * @var Entity|null
     */
    private ?Entity $componentEntity;

    /**
     * @param CallService $callService The call service
     * @param EntityManagerInterface $entityManager The entity manager
     * @param GithubApiService $githubApiService The github api service
     * @param GithubPubliccodeService $githubPubliccodeService The Github publicode service
     * @param SynchronizationService $synchronizationService The synchonization service
     * @param MappingService $mappingServiceThe mapping service
     * @param LoggerInterface $pluginLogger The plugin version of the loger interface
     */
    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        GithubPubliccodeService $githubPubliccodeService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->githubPubliccodeService = $githubPubliccodeService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->logger = $pluginLogger;

        $this->configuration = [];
        $this->data = [];
    }//end __construct)()

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
        $this->githubPubliccodeService->setStyle($io);
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);

        return $this;
    }//end setStyle()

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
     * This function fetches repository data.
     *
     * @param string $slug endpoint to request
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null
     */
    public function getRepositoryFromUrl(string $slug)
    {
        // Make sync object.
        $source = $this->getSource('https://api.github.com');

        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
        } catch (Exception $e) {
            isset($this->io) && $this->io->error('Error found trying to fetch /repos/'.$slug.' '.$e->getMessage());
        }

        if (isset($response)) {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
            isset($this->io) && $this->io->info("Fetch and decode went succesfull for /repos/$slug");

            return $repository;
        }//end if

        return null;
    }//end getRepositoryFromUrl()

    /**
     * Get an organisation from https://api.github.com/orgs/{org}.
     *
     * @param string $name
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return ObjectEntity|null
     */
    public function getOrganisation(string $name): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }

        isset($this->io) && $this->io->info('Getting organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name);

        $organisation = json_decode($response->getBody()->getContents(), true);

        if (!$organisation) {
            isset($this->io) && $this->io->error('Could not find an organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if
        $organisation = $this->importOrganisation($organisation);
        if ($organisation === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        isset($this->io) && $this->io->success('Found organisation with name: '.$name);

        return $organisation;
    }//end getOrganisation()

    /**
     * @param $organisation
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return ObjectEntity|null
     */
    public function importOrganisation($organisation): ?ObjectEntity
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        $organisationEntity = $this->getEntity('https://opencatalogi.nl/oc.organisation.schema.json');
        $organisationMapping = $this->getMapping('https://api.github.com/organisation');

        $synchronization = $this->synchronizationService->findSyncBySource($source, $organisationEntity, $organisation['id']);

        isset($this->io) && $this->io->comment('Mapping object'.$organisation['login']);
        isset($this->io) && $this->io->comment('The mapping object '.$organisationMapping);

        isset($this->io) && $this->io->comment('Checking organisation '.$organisation['login']);
        $synchronization->setMapping($organisationMapping);
        $synchronization = $this->synchronizationService->synchronize($synchronization, $organisation);
        isset($this->io) && $this->io->comment('Organisation synchronization created with id: '.$synchronization->getId()->toString());

        return $synchronization->getObject();
    }//end importOrganisation()

    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param string $name
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return array|null
     */
    public function getOrganisationRepos(string $name): ?array
    {
        // Do we have a source?
        $source = $this->getSource('https://api.github.com');
        if (!$this->checkGithubAuth($source)) {
            return null;
        }//end if

        $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');

        isset($this->io) && $this->io->info('Getting repos from organisation '.$name);
        $response = $this->callService->call($source, '/orgs/'.$name.'/repos');

        $repositories = json_decode($response->getBody()->getContents(), true);

        if (!$repositories) {
            isset($this->io) && $this->io->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if

        $owns = [];
        foreach ($repositories as $repository) {
            $repositoryObject = $this->githubPubliccodeService->importRepository($repository);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->flush();

            if ($component = $repositoryObject->getValue('component')) {
                $owns[] = $component;
                continue;
            }//end if

            $componentEntity = $this->getEntity('https://opencatalogi.nl/oc.component.schema.json');

            $component = new ObjectEntity($componentEntity);
            $component->hydrate([
                'name' => $repositoryObject->getValue('name'),
                'url'  => $repositoryObject,
            ]);
            $repositoryObject->setValue('component', $component);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->persist($component);
            $this->entityManager->flush();

            $owns[] = $component;
        }

        isset($this->io) && $this->io->success('Found '.count($owns).' repos from organisation with name: '.$name);

        return $owns;
    }//end getOrganisationRepos()

    /**
     * @param ObjectEntity $repository           the repository where we want to find an organisation for
     * @param ?array       $createdOrganizations the already created organizations during a parent loop so we dont waste time/performance on the same organizations
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return ObjectEntity|null
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository, array &$createdOrganizations = []): ?ObjectEntity
    {
        if (!$repository->getValue('url')) {
            isset($this->io) && $this->io->error('Repository url not set');

            return null;
        }//end if
        $source = $repository->getValue('source');
        $url = $repository->getValue('url');

        if ($source == null) {
            $domain = parse_url($url, PHP_URL_HOST);
            $domain == 'github.com' && $source = 'github';
            $domain == 'gitlab.com' && $source = 'gitlab';
        }//end if

        $url = trim(parse_url($url, PHP_URL_PATH), '/');

        switch ($source) {
            case 'github':
                // let's get the repository datar
                isset($this->io) && $this->io->info("Trying to fetch repository from: $url");

                if (!$github = $this->getRepositoryFromUrl($url)) {
                    return null;
                }//end if

                // Check if we didnt already loop through this organization during this loop
                if (isset($github['owner']['login']) && in_array($github['owner']['login'], $createdOrganizations)) {
                    isset($this->io) && $this->io->info('Organization already created/updated during this loop, continuing.');

                    return null;
                }//end if

                $repository = $this->githubPubliccodeService->importRepository($github);

                if ($github['owner']['type'] === 'Organization') {
                    // get organisation from github and set the property
                    $organisation = $this->getOrganisation($github['owner']['login']);

                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($repository);

                    // get organisation component and set the property
                    $owns = $this->getOrganisationRepos($github['owner']['login']);
                    $organisation->setValue('owns', $owns);

                    $this->entityManager->persist($organisation);
                    $this->entityManager->flush();

                    $createdOrganizations[] = $github['owner']['login'];
                } else {
                    isset($this->io) && $this->io->error('No organisation found for fetched repository');
                }
                break;
            case 'gitlab':
                // hetzelfde maar dan voor gitlab
                // @TODO code for gitlab as we do for github repositories
                isset($this->io) && $this->io->error("We dont do gitlab yet ($url)");
                break;
            default:
                isset($this->io) && $this->io->error("We dont know this type source yet ($source)");
                break;
        }

        isset($repositoryObject) && $this->entityManager->persist($repositoryObject);

        return null;
    }//end enrichRepositoryWithOrganisation()

    /**
     * Makes sure the action the action can actually runs and then executes functions to update a repository with fetched organization info.
     *
     * @param ?array      $data          data set at the start of the handler (not needed here)
     * @param ?array      $configuration configuration of the action          (not needed here)
     * @param string|null $repositoryId  optional repository id for testing for a single repository
     *
     * @throws GuzzleException|LoaderError|SyntaxError
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findOrganizationThroughRepositoriesHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        if ($repositoryId) {
            // If we are testing for one repository
            ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) && $this->enrichRepositoryWithOrganisation($repository);
            !$repository && $this->io->error('Could not find given repository');
        } else {
            $repositoryEntity = $this->getEntity('https://opencatalogi.nl/oc.repository.schema.json');

            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through repositories');
            $createdOrganizations = [];
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                $this->enrichRepositoryWithOrganisation($repository, $createdOrganizations);
            }
        }
        $this->entityManager->flush();

        isset($this->io) && $this->io->success('findOrganizationThroughRepositoriesHandler finished');

        return $this->data;
    }//end findOrganizationThroughRepositoriesHandler()
}//end class
