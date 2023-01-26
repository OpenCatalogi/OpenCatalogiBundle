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

/**
 * Loops through organizations (https://opencatalogi.nl/oc.organisation.schema.json)
 * and tries to find a opencatalogi.yaml on github with its organization name to update the organization object with that fetched opencatalogi.yaml data
 */
class FindOrganizationThroughRepositoriesService
{
    private EntityManagerInterface $entityManager;
    private array $configuration;
    private array $data;
    private SymfonyStyle $io;
    private CallService $callService;

    private Entity $organisationEntity;
    private Entity $repositoryEntity;
    private Source $githubApi;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->callService = $callService;
        $this->entityManager = $entityManager;

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
        // @TODO callService needs to be used without source, full domain here is variable
        // if ($path !== null) {
        //     $parse = parse_url($url);
        //     $url = str_replace([$path], '', $parse['path']);
        // }

        // if ($response = $this->callService->call('GET', $url)) {
        //     return json_decode($response->getBody()->getContents(), true);
        // }

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
        //    'organisation'            => $item['owner']['type'] === 'Organization' ? $this->getGithubOwnerInfo($item) : null,
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
        try {
            $response = $this->callService->call($this->githubApi, '/repos/'.$slug);
        } catch (Exception $e) {
            // @TODO Monolog ?
            isset($this->io) && $this->io->error("Error found trying to fetch '/repos/'.$slug : " . $e->getMessage());
            return null;
        }

        $response = $this->callService->decodeResponse($this->githubApi, $response, 'application/json');
        isset($this->io) && $this->io->success("Fetch and decode went succesfull for /repos/$slug");

        return $this->getGithubRepositoryInfo($response);
    }

    /**
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     *
     * @throws Exception
     */
    public function setRepositoryWithGithubInfo(ObjectEntity $repository, $github): ObjectEntity
    {
        $repository->hydrate([
            'source' => 'github',
            'name' => $github['name'],
            'url' => $github['url'],
            'avatar_url' => $github['avatar_url'],
            'last_change' => $github['last_change'],
            'stars' => $github['stars'],
            'fork_count' => $github['fork_count'],
            'issue_open_count' => $github['issue_open_count'],
            'programming_languages' => $github['programming_languages']
        ]);
        $this->entityManager->persist($repository);

        return $repository;
    }

    /**
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     *
     * @throws Exception
     * @throws GuzzleException
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository): ?ObjectEntity
    {
        if (!$repository->getValue('url')) {
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
                // let's get the repository data
                $github = $this->getRepositoryFromUrl($url);
                if ($github !== null && array_key_exists('organisation', $github) && $github['organisation'] !== null) {
                    $repository = $this->setRepositoryWithGithubInfo($repository, $github);

                    if (!$this->entityManager->getRepository('App:ObjectEntity')->findByEntity($this->organisationEntity, ['github' => $github['organisation']['github']])) {
                        $organisation = new ObjectEntity();
                        $organisation->setEntity($this->organisationEntity);
                    } else {
                        $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findByEntity($this->organisationEntity, ['github' => $github['organisation']['github']])[0];
                    }

                    $organisation->setValue('owns', $github['organisation']['owns']);
                    $organisation->hydrate($github['organisation']);
                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($organisation);
                    $this->entityManager->persist($repository);
                    $this->entityManager->flush();
                    isset($this->io) && $this->io->success("Enriched repository");

                    return $repository;
                }
                break;
            case 'gitlab':
                // hetelfde maar dan voor gitlab
                isset($this->io) && $this->io->error("We dont do gitlab yet ($url)");
                break;
            default:
                // error voor onbeknd type
                isset($this->io) && $this->io->error("We dont know this type source yet ($source)");
                break;
        }

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
    }

    /**
     * Makes sure the action the action can actually runs and then executes functions to update a organization with fetched opencatalogi.yaml info
     * 
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */ 
    public function findOrganizationThroughRepositoriesHandler(?array $data = [], ?array $configuration = []): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $this->getRequiredGatewayObjects();
        isset($this->io) && $this->io->success('Action config succesfully loaded');

        // If we want to do it for al repositories
        foreach ($this->repositoryEntity->getObjectEntities() as $repository) {
            $this->enrichRepositoryWithOrganisation($repository);
        }

        
        isset($this->io) && $this->io->success('findOrganizationThroughRepositoriesHandler finished');

        return $this->data;
    }
}
