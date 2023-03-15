<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

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
    private GithubPubliccodeService $gitService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @param CallService             $callService       The call service
     * @param EntityManagerInterface  $entityManager     The entity manager
     * @param GithubApiService        $githubApiService  The github api service
     * @param GithubPubliccodeService $gitService        The Github publicode service
     * @param SynchronizationService  $syncService       The synchonization service
     * @param MappingService          $mappingServiceThe mapping service
     * @param LoggerInterface         $pluginLogger      The plugin version of the loger interface
     * @param GatewayResourceService  $resourceService   The Gateway Resource Service.
     */
    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService,
        GithubPubliccodeService $gitService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;
        $this->gitService = $gitService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->pluginLogger = $pluginLogger;
        $this->resourceService = $resourceService;

        $this->configuration = [];
        $this->data = [];
    }//end __construct)()

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
            $this->pluginLogger->error('No auth set for Source: '.$source->getName().'.', ['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');

        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch /repos/'.$slug.' '.$e->getMessage(), ['plugin'=>'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === true) {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
            $this->pluginLogger->info("Fetch and decode went succesfull for /repos/$slug", ['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        if ($this->checkGithubAuth($source) === false) {
            return null;
        }//end if

        $this->pluginLogger->info('Getting organisation '.$name, ['plugin'=>'open-catalogi/open-catalogi-bundle']);
        $response = $this->callService->call($source, '/orgs/'.$name);

        $organisation = json_decode($response->getBody()->getContents(), true);

        if ($organisation === null) {
            $this->pluginLogger->error('Could not find an organisation with name: '.$name.' and with source: '.$source->getName(), ['plugin'=>'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        $organisation = $this->importOrganisation($organisation);
        if ($organisation === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        $this->pluginLogger->debug('Found organisation with name: '.$name, ['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        $organisationEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.organisation.schema.json', 'open-catalogi/open-catalogi-bundle');
        $organisationMapping = $this->resourceService->getMapping('https://api.github.com/oc.githubOrganisation.mapping.json', 'open-catalogi/open-catalogi-bundle');

        $synchronization = $this->syncService->findSyncBySource($source, $organisationEntity, $organisation['id']);

        $this->pluginLogger->debug('Mapping object'.$organisation['login'], ['plugin'=>'open-catalogi/open-catalogi-bundle']);
        $this->pluginLogger->debug('The mapping object '.$organisationMapping, ['plugin'=>'open-catalogi/open-catalogi-bundle']);

        $this->pluginLogger->debug('Checking organisation '.$organisation['login'], ['plugin'=>'open-catalogi/open-catalogi-bundle']);
        $synchronization->setMapping($organisationMapping);
        $synchronization = $this->syncService->synchronize($synchronization, $organisation);
        $this->pluginLogger->debug('Organisation synchronization created with id: '.$synchronization->getId()->toString(), ['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        if ($this->checkGithubAuth($source) === false) {
            return null;
        }//end if

        $this->pluginLogger->info('Getting repos from organisation '.$name, ['plugin'=>'open-catalogi/open-catalogi-bundle']);
        $response = $this->callService->call($source, '/orgs/'.$name.'/repos');

        $repositories = json_decode($response->getBody()->getContents(), true);

        if ($repositories === null) {
            $this->pluginLogger->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if

        $owns = [];
        foreach ($repositories as $repository) {
            $repositoryObject = $this->gitService->importRepository($repository);
            $this->entityManager->persist($repositoryObject);
            $this->entityManager->flush();

            if ($component = $repositoryObject->getValue('component')) {
                $owns[] = $component;
                continue;
            }//end if

            $componentEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

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

        $this->pluginLogger->debug('Found '.count($owns).' repos from organisation with name: '.$name, ['plugin'=>'open-catalogi/open-catalogi-bundle']);

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
        if ($repository->getValue('url') === null) {
            $this->pluginLogger->error('Repository url not set', ['plugin'=>'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if
        $source = $repository->getValue('source');
        $url = $repository->getValue('url');

        if ($source == null) {
            $domain = \Safe\parse_url($url, PHP_URL_HOST);
            $domain == 'github.com' && $source = 'github';
            $domain == 'gitlab.com' && $source = 'gitlab';
        }//end if

        $url = trim(\Safe\parse_url($url, PHP_URL_PATH), '/');

        switch ($source) {
            case 'github':
                // let's get the repository datar
                $this->pluginLogger->info("Trying to fetch repository from: $url", ['plugin'=>'open-catalogi/open-catalogi-bundle']);

                if (($github = $this->getRepositoryFromUrl($url)) === null) {
                    return null;
                }//end if

                // Check if we didnt already loop through this organization during this loop
                if (isset($github['owner']['login']) === true
                    && in_array($github['owner']['login'], $createdOrganizations) === true
                ) {
                    $this->pluginLogger->info('Organization already created/updated during this loop, continuing.', ['plugin'=>'open-catalogi/open-catalogi-bundle']);

                    return null;
                }//end if

                $repository = $this->gitService->importRepository($github);

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
                    $this->pluginLogger->error('No organisation found for fetched repository', ['plugin'=>'open-catalogi/open-catalogi-bundle']);
                }
                break;
            case 'gitlab':
                // hetzelfde maar dan voor gitlab
                // @TODO code for gitlab as we do for github repositories
                $this->pluginLogger->error("We dont do gitlab yet ($url)", ['plugin'=>'open-catalogi/open-catalogi-bundle']);
                break;
            default:
                $this->pluginLogger->error("We dont know this type source yet ($source)", ['plugin'=>'open-catalogi/open-catalogi-bundle']);
                break;
        }

        if (isset($repository) === true) {
            $this->entityManager->persist($repository);
        }

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

        if ($repositoryId !== null) {
            // If we are testing for one repository
            if (($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) !== null) {
                $this->enrichRepositoryWithOrganisation($repository);
            }

            if ($repository === null) {
                $this->pluginLogger->error('Could not find given repository', ['plugin'=>'open-catalogi/open-catalogi-bundle']);
            }
        } else {
            $repositoryEntity = $this->resourceService->getSchema('https://opencatalogi.nl/oc.repository.schema.json', 'open-catalogi/open-catalogi-bundle');

            // If we want to do it for al repositories
            $this->pluginLogger->info('Looping through repositories', ['plugin'=>'open-catalogi/open-catalogi-bundle']);
            $createdOrganizations = [];
            foreach ($repositoryEntity->getObjectEntities() as $repository) {
                $this->enrichRepositoryWithOrganisation($repository, $createdOrganizations);
            }
        }

        $this->entityManager->flush();

        $this->pluginLogger->debug('findOrganizationThroughRepositoriesHandler finished', ['plugin'=>'open-catalogi/open-catalogi-bundle']);

        return $this->data;
    }//end findOrganizationThroughRepositoriesHandler()
}//end class
