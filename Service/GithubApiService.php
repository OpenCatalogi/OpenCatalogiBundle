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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Exception;

class GithubApiService
{
    private ParameterBagInterface $parameterBag;
    private CallService $callService;
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private MappingService $mappingService;

    private ?Mapping $repositoryMapping;
    private ?Mapping $organizationMapping;
    private ?Entity $repositoryEntity;
    private ?Entity $organizationEntity;
    private ?Source $githubApiSource;

    // private ?Client $githubClient;
    // private ?Client $githubusercontentClient;

    public function __construct(
        ParameterBagInterface $parameterBag,
        CallService $callService,
        EntityManagerInterface $entityManager,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->parameterBag = $parameterBag;
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;

        $this->repositoryMapping = null;
        $this->organizationMapping = null;
        $this->repositoryEntity = null;
        $this->organizationEntity = null;
        $this->githubApiSource = null;

        // $this->githubClient = $this->parameterBag->get('github_key') ? new Client(['base_uri' => 'https://api.github.com/', 'headers' => ['Authorization' => 'Bearer '.$this->parameterBag->get('github_key')]]) : null;
        // $this->githubusercontentClient = new Client(['base_uri' => 'https://raw.githubusercontent.com/']);
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

        return $this;
    }

    public function getSource()
    {
        !isset($this->source) && $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com/']);
        if (!isset($this->source)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a Source for the Github API');

            return [];
        }

        return $this->source;
    }

    // /**
    //  * This function check if the github key is provided.
    //  *
    //  * @return Response|null
    //  */
    // public function checkGithubKey(): ?Response
    // {
    //     if (!$this->githubClient) {
    //         return new Response(
    //             'Missing github_key in env',
    //             Response::HTTP_BAD_REQUEST,
    //             ['content-type' => 'json']
    //         );
    //     }

    //     return null;
    // }

    // /**
    //  * This function gets the content of the given url.
    //  *
    //  * @param string      $url
    //  * @param string|null $path
    //  *
    //  * @throws GuzzleException
    //  *
    //  * @return array|null
    //  */
    // public function requestFromUrl(string $url, ?string $path = null): ?array
    // {
    //     if ($path !== null) {
    //         $parse = parse_url($url);
    //         $url = str_replace([$path], '', $parse['path']);
    //     }

    //     if ($response = $this->githubClient->request('GET', $url)) {
    //         return json_decode($response->getBody()->getContents(), true);
    //     }

    //     return null;
    // }

    /**
     * This function gets all the github repository details.
     *
     * @param array $item a repository from github with a publicclode.yaml file
     *
     * @throws GuzzleException
     *
     * @return array
     */
    public function getGithubRepositoryInfo(array $item): array
    {
        return [
            'source'                  => 'github',
            'name'                    => $item['name'],
            'url'                     => $item['html_url'],
            'avatar_url'              => $item['owner']['avatar_url'],
            'last_change'             => $item['updated_at'],
            'stars'                   => $item['stargazers_count'],
            'fork_count'              => $item['forks_count'],
            'issue_open_count'        => $item['open_issues_count'],
            //            'merge_request_open_count'   => $this->requestFromUrl($item['merge_request_open_count']),
            'programming_languages'   => $this->requestFromUrl($item['languages_url']),
            //            'organisation'            => $item['owner']['type'] === 'Organization' ? $this->getGithubOwnerInfo($item) : null,
            //            'topics' => $this->requestFromUrl($item['topics'], '{/name}'),
            //                'related_apis' => //
        ];
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $slug
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getRepositoryFromUrl(string $slug)
    {
        if ($this->checkGithubKey()) {
            return $this->checkGithubKey();
        }

        try {
            $response = $this->callService->call('GET', 'repos/'.$slug);
        } catch (ClientException $exception) {
            var_dump($exception->getMessage());

            return null;
        }

        // try {
        //     $response = $this->githubClient->request('GET', 'repos/'.$slug);
        // } catch (ClientException $exception) {
        //     var_dump($exception->getMessage());

        //     return null;
        // }

        $response = json_decode($response->getBody()->getContents(), true);

        return $this->getGithubRepositoryInfo($response);
    }

    /**
     * This function gets the content of the given url.
     *
     * @param string      $url
     * @param string|null $path
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getGithubOwnerRepositories(string $url, ?string $path = null): ?array
    {
        if ($path !== null) {
            $parse = parse_url($url);
            $url = str_replace([$path], '', $parse['path']);
        }

        // if ($response = $this->githubClient->request('GET', $url)) {
        //     $responses = json_decode($response->getBody()->getContents(), true);

        //     $urls = [];
        //     foreach ($responses as $item) {
        //         $urls[] = $item['html_url'];
        //     }

        //     return $urls;
        // }

        return null;
    }

    /**
     * This function gets the github owner details.
     *
     * @param array $item a repository from github
     *
     * @throws GuzzleException
     *
     * @return array
     */
    public function getGithubOwnerInfo(array $item): array
    {
        // @TODO

        return [
            'id'          => $item['owner']['id'],
            'name'        => $item['owner']['login'],
            'description' => null,
            'logo'        => $item['owner']['avatar_url'] ?? null,
            //            'owns'        => $this->getGithubOwnerRepositories($item['owner']['repos_url']),
            'token'       => null,
            'github'      => $item['owner']['html_url'] ?? null,
            'website'     => null,
            'phone'       => null,
            'email'       => null,
        ];
    }

    // /**
    //  * This function is searching for repositories containing a publiccode.yaml file.
    //  *
    //  * @param string $organizationName
    //  * @param string $repositoryName
    //  *
    //  * @throws GuzzleException
    //  *
    //  * @return array|null
    //  */
    // public function getPubliccodeForGithubEvent(string $organizationName, string $repositoryName): ?array
    // {
    //     $response = null;

    //     try {
    //         $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/main/publiccode.yaml');
    //     } catch (ClientException $exception) {
    //         var_dump($exception->getMessage());
    //     }

    //     if ($response == null) {
    //         try {
    //             $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/master/publiccode.yaml');
    //         } catch (ClientException $exception) {
    //             var_dump($exception->getMessage());

    //             return null;
    //         }
    //     }

    //     if ($response == null) {
    //         try {
    //             $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/main/publiccode.yml');
    //         } catch (ClientException $exception) {
    //             var_dump($exception->getMessage());

    //             return null;
    //         }
    //     }

    //     if ($response == null) {
    //         try {
    //             $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/master/publiccode.yml');
    //         } catch (ClientException $exception) {
    //             var_dump($exception->getMessage());

    //             return null;
    //         }
    //     }

    //     try {
    //         $publiccode = Yaml::parse($response->getBody()->getContents());
    //     } catch (ParseException $exception) {
    //         var_dump($exception->getMessage());

    //         return null;
    //     }

    //     return $publiccode;
    // }

    // /**
    //  * This function is searching for repositories containing a publiccode.yaml file.
    //  *
    //  * @param string $url
    //  *
    //  * @throws GuzzleException
    //  *
    //  * @return array|null|Response
    //  */
    // public function getPubliccode(string $url)
    // {
    //     $parseUrl = parse_url($url);
    //     $code = explode('/blob/', $parseUrl['path']);

    //     try {
    //         $response = $this->githubusercontentClient->request('GET', $code[0].'/'.$code[1]);
    //     } catch (ClientException $exception) {
    //         var_dump($exception->getMessage());

    //         return null;
    //     }

    //     try {
    //         $publiccode = Yaml::parse($response->getBody()->getContents());
    //     } catch (ParseException $exception) {
    //         var_dump($exception->getMessage());

    //         return null;
    //     }

    //     return $publiccode;
    // }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $slug
     *
     * @return array|null|Response
     */
    public function checkPublicRepository(string $slug)
    {
        if (!isset($this->githubApiSource) && !$this->githubApiSource = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Source: Github API');

            return [];
        }
    
        try {
            $response = $this->callService->call($this->githubApiSource, '/repos/'.$slug);
            $repository = $this->callService->decodeResponse($this->githubApiSource, $response);
        } catch (Exception $exception) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error("Exception while checking if public repository: {$exception->getMessage()}");
    
            return [];
        }

        return $repository['private'];
    }

    /**
     * Makes sure this action has all the gateway objects it needs.
     */
    private function getRequiredGatewayObjects()
    {
        // get github source
        if (!isset($this->githubApiSource) && !$this->githubApiSource = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => 'GitHub API'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find Source: Github API');

            return [];
        }
        if (!isset($this->repositoryEntity) && !$this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for reference https://opencatalogi.nl/oc.repository.schema.json');

            return [];
        }
        if (!isset($this->organizationEntity) && !$this->organizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for reference https://opencatalogi.nl/oc.organisation.schema.json');

            return [];
        }

        if (!isset($this->repositoryMapping) && !$this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json'])) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a repository for reference https://opencatalogi.nl/oc.repository.schema.json');

            return [];
        }

        // check if github source has authkey
        if (!$this->githubApiSource->getApiKey()) {
            isset($this->io) && $this->io->error('No auth set for Source: GitHub API');

            return [];
        }
    }

    /**
     * Searches github for publiccode files @TODO testing.
     *
     * @param $data
     * @param $configuration
     *
     * @return array
     */
    public function handleFindRepositoriesContainingPubliccode($data = [], $configuration = []): array
    {
        $this->getRequiredGatewayObjects();

        // Build the query
        $query = [
            'page'     => 1,
            'per_page' => 1,
            'order'    => 'desc',
            'sort'     => 'author-date',
            'q'        => 'publiccode in:path path:/  extension:yml', // so we are looking for a yaml file called publiccode based in the repo root
        ];

        // find on publiccode.yml
        $responseYml = $this->callService->call($this->githubApiSource, '/search/code', 'GET', ['query' => $query]);
        $repositoriesYml = $this->callService->decodeResponse($this->githubApiSource, $responseYml);

        $query['q'] = 'publiccode in:path path:/  extension:yaml'; // switch from yml to yaml

        // find on publiccode.yaml
        $responseYaml = $this->callService->call($this->githubApiSource, '/search/code', 'GET', ['query' => $query]);
        $repositoriesYaml = $this->callService->decodeResponse($this->githubApiSource, $responseYaml);

        // merge the result
        $repositories = array_merge($repositoriesYaml, $repositoriesYml);

        foreach ($repositories['items'] as $repository) {
            if (isset($repository['repository'])) {
                $repositoryObject = $this->handleRepositoryArray($repository['repository']);
                $this->entityManager->persist($repositoryObject);
                dump($repositoryObject->getId()->toString());
                dump($repositoryObject->toArray());

                // // REMOVE/DISABLE AFTER TESTING
                // $this->entityManager->flush();
                // return $data;
            }
        }

        $this->entityManager->flush();

        return $data;
    }

    /**
     * Turn an repro array into an object we can handle @TODO testing.
     *
     * @param array   $repro
     * @param Mapping $mapping
     *
     * @return ?ObjectEntity
     */
    public function handleRepositoryArray(array $repository, ?Entity $repositoryEntity = null, ?Mapping $mapping = null, ?Source $githubApiSource = null): ?ObjectEntity
    {
        // check for mapping
        if (!$this->repositoryMapping && !$mapping) {
            $this->io->error('Repository mapping not set/given');

            return null;
        }

        // Mapp the repro to something ussefull
        // @TODO mapping aint right
        $mappedRepository = $this->mappingService->mapping($this->repositoryMapping ?? $mapping, $repository);

        // Handle sync
        $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource ?? $githubApiSource, $this->repositoryEntity ?? $repositoryEntity, $mappedRepository['url']);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $mappedRepository);
        $repositoryObject = $synchronization->getObject();
        $repository = $repositoryObject->toArray();

        if (isset($repository['organisation'])) {
            $organisationObject = $this->handleOrganizationArray($repository['organisation']);
            $repositoryObject->setValue('organization', $organisationObject->getId()->toString());
        }

        return $repositoryObject;
    }

    /**
     * Turn an organisation array into an object we can handle.
     *
     * @param array   $repro
     * @param Mapping $mapping
     *
     * @return ObjectEntity
     */
    public function handleOrganizationArray(array $organisation, ?Entity $organizationEntity = null, ?Mapping $mapping = null, ?Source $githubApiSource = null): ObjectEntity
    {

        // check for mapping
        if (!$this->organizationMapping && !$mapping) {
            $this->io->error('Organization mapping not set/given');

            return null;
        }

        // Mapp the repro to something ussefull
        $mappedOrganisation = $this->mappingService->mapping($this->organizationMapping ?? $mapping, $organisation);

        // Turn the organisation into a synchronyzed object
        // $synchronization = $this->synchronizationService->findSyncBySource($this->githubApiSource ?? $githubApiSource, $this->organizationEntity ?? $organizationEntity, $organizatioNameOrId?);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $mappedOrganisation);
        $organisationObject = $synchronization->getObject();
        $organisation = $organisationObject->toArray();

        if (isset($organisation['repositories'])) {
            foreach ($organisation['repositories'] as $repository) {
                $repositoryObject = $this->handlerepositoryArray($repository);
                // Organizations don't have repositories so we need to set to organization on the repo site and persist that
                $repositoryObject->setValue('organization', $organisation);
                $this->entityManager->persist($repository);
            }
        }

        return $organisationObject;
    }
}
