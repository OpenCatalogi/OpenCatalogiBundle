<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use CommonGateway\CoreBundle\Service\CallService;
use App\Entity\ObjectEntity;
use App\Entity\Gateway as Source;
use App\Service\SynchronySationService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class GithubApiService
{
    private ParameterBagInterface $parameterBag;
    private ?Client $githubClient;
    private ?Client $githubusercontentClient;
    private CallService $callService;
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;
    private Source $source;
    private SynchronySationService $synchronySationService;

    public function __construct(
        ParameterBagInterface $parameterBag,
        CallService $callService,
        EntityManagerInterface $entityManager,
        SynchronySationService $synchronySationService
    ) {
        $this->parameterBag = $parameterBag;
        $this->githubClient = $this->parameterBag->get('github_key') ? new Client(['base_uri' => 'https://api.github.com/', 'headers' => ['Authorization' => 'Bearer '.$this->parameterBag->get('github_key')]]) : null;
        $this->githubusercontentClient = new Client(['base_uri' => 'https://raw.githubusercontent.com/']);
        $this->entityManager = $entityManager;
        $this->synchronySationService = $synchronySationService;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function getSource(){
        !isset($this->source) && $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => 'https://api.github.com/']);
        if (!isset($this->source)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a Source for the Github API');
            return [];
        };

        return $this->source;
    }

    /**
     * This function check if the github key is provided.
     *
     * @return Response|null
     */
    public function checkGithubKey(): ?Response
    {
        if (!$this->githubClient) {
            return new Response(
                'Missing github_key in env',
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'json']
            );
        }

        return null;
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
    public function requestFromUrl(string $url, ?string $path = null): ?array
    {
        if ($path !== null) {
            $parse = parse_url($url);
            $url = str_replace([$path], '', $parse['path']);
        }

        if ($response = $this->githubClient->request('GET', $url)) {
            return json_decode($response->getBody()->getContents(), true);
        }

        return null;
    }

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

        if ($response = $this->githubClient->request('GET', $url)) {
            $responses = json_decode($response->getBody()->getContents(), true);

            $urls = [];
            foreach ($responses as $item) {
                $urls[] = $item['html_url'];
            }

            return $urls;
        }

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

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $organizationName
     * @param string $repositoryName
     *
     * @throws GuzzleException
     *
     * @return array|null
     */
    public function getPubliccodeForGithubEvent(string $organizationName, string $repositoryName): ?array
    {
        $response = null;

        try {
            $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/main/publiccode.yaml');
        } catch (ClientException $exception) {
            var_dump($exception->getMessage());
        }

        if ($response == null) {
            try {
                $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/master/publiccode.yaml');
            } catch (ClientException $exception) {
                var_dump($exception->getMessage());

                return null;
            }
        }

        if ($response == null) {
            try {
                $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/main/publiccode.yml');
            } catch (ClientException $exception) {
                var_dump($exception->getMessage());

                return null;
            }
        }

        if ($response == null) {
            try {
                $response = $this->githubusercontentClient->request('GET', $organizationName.'/'.$repositoryName.'/master/publiccode.yml');
            } catch (ClientException $exception) {
                var_dump($exception->getMessage());

                return null;
            }
        }

        try {
            $publiccode = Yaml::parse($response->getBody()->getContents());
        } catch (ParseException $exception) {
            var_dump($exception->getMessage());

            return null;
        }

        return $publiccode;
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @param string $url
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getPubliccode(string $url)
    {
        $parseUrl = parse_url($url);
        $code = explode('/blob/', $parseUrl['path']);

        try {
            $response = $this->githubusercontentClient->request('GET', $code[0].'/'.$code[1]);
        } catch (ClientException $exception) {
            var_dump($exception->getMessage());

            return null;
        }

        try {
            $publiccode = Yaml::parse($response->getBody()->getContents());
        } catch (ParseException $exception) {
            var_dump($exception->getMessage());

            return null;
        }

        return $publiccode;
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
    public function checkPublicRepository(string $slug)
    {
        if ($this->checkGithubKey()) {
            return $this->checkGithubKey();
        }

        try {
            $response = $this->githubClient->request('GET', 'repos/'.$slug);
        } catch (ClientException $exception) {
            return new Response(
                $exception,
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'json']
            );
        }

        $response = json_decode($response->getBody()->getContents(), true);

        return $response['private'];
    }

    /**
     * Searches github for publiccode files
     *
     * @param $data
     * @param $configuration
     * @return array
     */
    public function handleFindRepositoriesContainingPubliccode($data = [], $configuration = []): array{

       // get github source
        if(!$source = $this->getSource()){
            return $data;
        }

        // check if github source has authkey

        // Build the query

        $query = [
            'page'     => 1,
            'per_page' => 200,
            'order'    => 'desc',
            'sort'     => 'author-date',
            'q'        => 'publiccode in:path path:/  extension:yml', // so we are looking for a yaml file called publiccode based in the repo root
        ];


        // find on publiccode.yml
        $repositoriesYml = $this->callService->call($source,'/search/code',['query' => $this->query]);

        $query['q'] = 'publiccode in:path path:/  extension:yaml'; // switch from yml to yaml
        // find onf publiccode.yaml
        $repositoriesYaml = $this->callService->call($source,'/search/code',['query' => $this->query]);

        // merge rhe result
        $repositories = array_merge($repositoriesYaml['results'],$repositoriesYml['results']);

        foreach($repositories as $reprository){
            $reprository = $this->handleReprositoryArray($reprository);
            $this->entityManager->persist($reprository);
        }

        $this->entityManager->flush();

        return $data;
    }

    /**
     * Turn an repro array into an object we can handle
     *
     * @param array $repro
     * @return ObjectEntity
     */
    public function handleReprositoryArray(array $reprository): ObjectEntity {

        // check for mapping

        // Mapp the repro to something ussefull
        $mappedRepro = $this->mappingService->mapping($this->repositoryMapping, $reprository);

        // Turn the organisation into a synchronyzed object
        $synchronysation = $this->synchronySationService->synchronize($mappedRepro, $this->reprositoryEntity, $this->source());
        $reprositoryObject = $synchronysation->getObject();

        if(isset($reprository['organisation'])){
            $organisation = $this->handleReprositoryArray($reprository['organisation']);
            $reprository->setValue('organization', $organisation);
        }

        return $reprositoryObject;

    }

    /**
     * Turn an organisation array into an object we can handle
     *
     * @param array $repro
     * @return ObjectEntity
     */
    public function handleOrganizationArray(array $organisation): ObjectEntity {

        // check for mapping

        // Mapp the repro to something ussefull
        $mappedOrganisation = $this->mappingService->mapping($this->repositoryMapping, $organisation);

        // Turn the organisation into a synchronyzed object
        $synchronysation = $this->synchronySationService->synchronize($mappedOrganisation, $this->organizationEntity, $this->source());
        $organisationObject = $synchronysation->getObject();

        if(isset($organisation['repositories'])){
            foreach($organisation['repositories'] as $reprository){
                $reprository = $this->handleReprositoryArray($reprository);
                // Organizations don't have repositories so we need to set to organization on the repo site and persist that
                $reprository->setValue('organization', $organisation);
                $this->entityManager->persist($reprository);
            }
        }

        return $organisationObject;
    }
}
