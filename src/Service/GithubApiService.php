<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

class GithubApiService
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
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;


    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param CallService            $callService     The Call Service
     * @param CacheService           $cacheService    The Cache Service
     * @param MappingService         $mappingService  The Mapping Service
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        CacheService $cacheService,
        MappingService $mappingService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService
    ) {
        $this->entityManager   = $entityManager;
        $this->callService     = $callService;
        $this->cacheService    = $cacheService;
        $this->mappingService  = $mappingService;
        $this->pluginLogger    = $pluginLogger;
        $this->resourceService = $resourceService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


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
            $this->pluginLogger->error('No auth set for Source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return false;
        }//end if

        return true;

    }//end checkGithubAuth()

    /**
     * This function gets the publiccode file from the github user content.
     *
     * @param string $repositoryUrl The url of the repository
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getPubliccodeFromRawUserContent(string $repositoryUrl): ?array
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubusercontent.source.json', 'open-catalogi/open-catalogi-bundle');
        if ($source === null) {
            return $this->data;
        }

        // Get the path from the url to make the call.
        $endpoint = \Safe\parse_url($repositoryUrl)['path'];
        try {
            $response = $this->callService->call($source, $endpoint);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch '.$repositoryUrl.' '.$e->getMessage());
        }

        return $this->callService->decodeResponse($source, $response, 'text/yaml');

    }//end getPubliccodeFromRawUserContent()


    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getRepository(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/repos/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repository = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()

    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $repositoryUrl   The url of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getPubliccodesFromRepo(string $repositoryUrl, Source $source): ?array
    {
        $url = trim(\Safe\parse_url($repositoryUrl, PHP_URL_PATH), '/');

        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:publiccode extension:yaml extension:yml repo:{$url}"];

        // Find the publiccode.yaml file(s).
        try {
            $response = $this->callService->call($source, '/search/code', 'GET', $queryConfig);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Error found trying to fetch '.$source->getLocation().'/search/code'.' '.$exception->getMessage());
        }

        if (isset($response) === false) {
            return null;
        }

        $repositories = $this->callService->decodeResponse($source, $response);

        $this->pluginLogger->debug('Found '.count($repositories).' publiccode file(s).', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $repositories;

    }//end getRepository()

    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getOrganisation(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/repos/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repository = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()

    /**
     * Get a repository through the repositories of the given source
     *
     * @param string $name   The name of the repository.
     * @param Source $source The source to sync from.
     *
     * @return array|null The imported repository as array.
     */
    public function getOrganisationRepos(string $name, Source $source): ?array
    {
        $this->pluginLogger->debug('Getting repository '.$name.'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/repos/'.$name);
        } catch (ClientException $exception) {
            $this->pluginLogger->error($exception->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === false) {
            return null;
        }

        $repository = \Safe\json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find a repository with name: '.$name.' and with source: '.$source->getName().'.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        return $repository;

    }//end getRepository()


    /**
     * This function checks if a github repository is public.
     *
     * @param string $slug The slug of the repository
     *
     * @return bool Boolean for if the repository is public.
     */
    public function checkPublicRepository(string $slug): bool
    {
        $source = $this->resourceService->getSource('https://opencatalogi.nl/source/oc.GitHubAPI.source.json', 'open-catalogi/open-catalogi-bundle');

        $slug = preg_replace('/^https:\/\/github.com\//', '', $slug);
        $slug = rtrim($slug, '/');

        try {
            $response   = $this->callService->call($source, '/repos/'.$slug);
            $repository = $this->callService->decodeResponse($source, $response);
        } catch (Exception $exception) {
            // @TODO Monolog ?
            $this->pluginLogger->error("Exception while checking if public repository: {$exception->getMessage()}");

            return false;
        }

        return $repository['private'] === false;

    }//end checkPublicRepository()


}//end class
