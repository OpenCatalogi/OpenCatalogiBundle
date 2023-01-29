<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Service\CallService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;
use App\Entity\Mapping;

/**
 * Loops through repositories (https://opencatalogi.nl/oc.repository.schema.json) and updates it with fetched organization info
 */
class FindOrganizationThroughRepositoriesService
{
    private EntityManagerInterface $entityManager;
    private array $configuration;
    private array $data;
    private SymfonyStyle $io;
    private CallService $callService;
    private GithubApiService $githubApiService;

    private Entity $organisationEntity;
    private Mapping $organisationMapping;
    private Entity $repositoryEntity;
    private Mapping $repositoryMapping;
    private Source $githubApi;

    public function __construct(
        CallService $callService,
        EntityManagerInterface $entityManager,
        GithubApiService $githubApiService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;
        $this->githubApiService = $githubApiService;

        $this->configuration = [];
        $this->data = [];
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

    /**
     * This function gets the content of the given url. @TODO needs testing with proper data
     *
     * @param string      $url
     * @param string|null $path
     *
     * @return array|null
     */
    public function requestFromUrl(string $url, ?string $path = null): ?array
    {
        // @TODO url is unknown yet (needs testing with proper data
        isset($this->io) && $this->io->error("Find out what url $ code is continue development: $url");
        return null;
        // if ($path !== null) {
        //     $parse = parse_url($url);
        //     $url = str_replace([$path], '', $parse['path']);
        // }

        // if ($response = $this->callService->call('GET', $url)) {
        //     return json_decode($response->getBody()->getContents(), true);
        // }

        return null;
    }

    // /**
    //  * This function gets all the github repository details.
    //  *
    //  * @param array $item a repository from github with a publicclode.yaml file
    //  *
    //  * @return array
    //  */
    // public function getGithubRepositoryInfo(array $item): array
    // {
    //     // @TODO MappingService?
    //     return [
    //         'source'                  => 'github',
    //         'name'                    => $item['name'],
    //         'url'                     => $item['html_url'],
    //         'avatar_url'              => $item['owner']['avatar_url'],
    //         'last_change'             => $item['updated_at'],
    //         'stars'                   => $item['stargazers_count'],
    //         'fork_count'              => $item['forks_count'],
    //         'issue_open_count'        => $item['open_issues_count'],
    //         //            'merge_request_open_count'   => $this->requestFromUrl($item['merge_request_open_count']),
    //         'programming_languages'   => $this->requestFromUrl($item['languages_url']),
    //     //    'organisation'            => $item['owner']['type'] === 'Organization' ? $this->getGithubOwnerInfo($item) : null,
    //         //            'topics' => $this->requestFromUrl($item['topics'], '{/name}'),
    //         //                'related_apis' => //
    //     ];
    // }

    /**
     * This function fetches repository data.
     *
     * @param string $slug endpoint to request
     *
     * @throws GuzzleException
     *
     * @return array|null|Response
     */
    public function getRepositoryFromUrl(string $slug)
    {
        try {
            $response = $this->callService->call($this->githubApi, '/repos/' . $slug);
        } catch (Exception $e) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error("Error found trying to fetch '/repos/'.$slug : " . $e->getMessage());
            return null;
        }

        $response = $this->callService->decodeResponse($this->githubApi, $response, 'application/json');
        isset($this->io) && $this->io->success("Fetch and decode went succesfull for /repos/$slug");

        return $response;
    }

    // /**
    //  * Hydrates the repository with earlier fetched github data
    //  * 
    //  * @param ObjectEntity $repository the repository where we want to find an organisation for
    //  * @param ?array       $github     fetched organization info from github
    //  * @throws Exception
    //  */
    // public function setRepositoryWithGithubInfo(ObjectEntity $repository, ?array $github): ObjectEntity
    // {
    //     $repository->hydrate(array_merge([
    //         'source' => 'github'
    //     ], $github));
    //     $this->entityManager->persist($repository);
    //     isset($this->io) && $this->io->success("Updated repo {$github['name']}");

    //     return $repository;
    // }


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

        $url = str_replace($this->githubApi->getLocation(), '', $url);
        if ($response = $this->callService->call($this->githubApi, $url)) {
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
            'owns'        => $this->getGithubOwnerRepositories($item['owner']['repos_url']),
            'token'       => null,
            'github'      => $item['owner']['html_url'] ?? null,
            'website'     => null,
            'phone'       => null,
            'email'       => null,
        ];
    }

    /**
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository): ?ObjectEntity
    {
        if (!$repository->getValue('url')) {
            isset($this->io) && $this->io->error("Repository url not set");
            return null;
        }
        $source = $repository->getValue('source');
        $url = $repository->getValue('url');

        if ($source == null) {
            $domain = parse_url($url, PHP_URL_HOST);
            $domain == 'github.com' && $source = 'github';
            $domain == 'gitlab.com' && $source = 'gitlab';
        }

        $url = trim(parse_url($url, PHP_URL_PATH), '/');

        switch ($source) {
            case 'github':
                // let's get the repository datar
                isset($this->io) && $this->io->info("Trying to fetch repository from: $url");
                $github = $this->getRepositoryFromUrl($url);
                if ($github['owner']['type'] === 'Organization') {
                    $github['organisation'] = $this->getGithubOwnerInfo($github);
                } else {
                    isset($this->io) && $this->io->error("No organisation found for fetched repository");
                }
                $repositoryObject = $this->githubApiService->handleRepositoryArray($github, $this->repositoryEntity, $this->repositoryMapping);

                // This code might nog be needed thanks to handleRepositoryArray code?
                // if (isset($github['organisation'])) {
                //     // $repository = $this->setRepositoryWithGithubInfo($repository, $github);

                //     if (!$this->entityManager->getRepository('App:ObjectEntity')->findByEntity($this->organisationEntity, ['github' => $github['organisation']['github']])) {
                //         $organisation = new ObjectEntity();
                //         $organisation->setEntity($this->organisationEntity);
                //     } else {
                //         $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findByEntity($this->organisationEntity, ['github' => $github['organisation']['github']])[0];
                //     }

                //     $organisation->setValue('owns', $github['organisation']['owns']);
                //     $organisation->hydrate($github['organisation']);
                //     $repository->setValue('organisation', $organisation);
                //     $this->entityManager->persist($organisation);
                //     $this->entityManager->persist($repository);
                //     $this->entityManager->flush();
                //     isset($this->io) && $this->io->success("Enriched repository");

                //     return $repository;
                // } else {
                //     isset($this->io) && $this->io->error("No organisation found for fetched repository");
                // }
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
    }

    /**
     * Makes sure this action has all the gateway objects it needs
     */
    private function getRequiredGatewayObjects()
    {
        !isset($this->organisationEntity) && $this->organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json']);
        if (!isset($this->organisationEntity)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for https://opencatalogi.nl/oc.organisation.schema.json');
            return [];
        }

        !isset($this->repositoryEntity) && $this->repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if (!isset($this->repositoryEntity)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a entity for https://opencatalogi.nl/oc.repository.schema.json');
            return [];
        }

        !isset($this->githubApi) && $this->githubApi = $this->entityManager->getRepository('App:Gateway')->findOneBy(['name' => 'GitHub API']);
        if (!isset($this->githubApi)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a Source for Github API');
            return [];
        };

        !isset($this->repositoryMapping) && $this->repositoryMapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
        if (!isset($this->repositoryMapping)) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error('Could not find a repository for reference https://opencatalogi.nl/oc.repository.schema.json');
            return [];
        };
    }

    /**
     * Loops through repositories to enrich with organisation
     */
    private function loopThroughRepositories()
    {
        foreach ($this->repositoryEntity->getObjectEntities() as $repository) {
            $this->enrichRepositoryWithOrganisation($repository);
        }
    }

    /**
     * Makes sure the action the action can actually runs and then executes functions to update a repository with fetched organization info
     * 
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     * @param ?array $repositoryId  optional repository id for testing for a single repository   
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findOrganizationThroughRepositoriesHandler(?array $data = [], ?array $configuration = [], ?string $repositoryId = null): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $this->getRequiredGatewayObjects();
        isset($this->io) && $this->io->info('Action config succesfully loaded');

        if ($repositoryId) {
            // If we are testing for one repository
            ($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) && $this->enrichRepositoryWithOrganisation($repository);
            !$repository && $this->io->error('Could not find given repository');
        } else {
            // If we want to do it for al repositories
            isset($this->io) && $this->io->info('Looping through repositories');
            $this->loopThroughRepositories();
        }
        $this->entityManager->flush();


        isset($this->io) && $this->io->success('findOrganizationThroughRepositoriesHandler finished');

        return $this->data;
    }
}
