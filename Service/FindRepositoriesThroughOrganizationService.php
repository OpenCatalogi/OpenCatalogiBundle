<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;

class FindRepositoriesThroughOrganizationService
{
    private EntityManagerInterface $entityManager;
    private GithubApiService $githubService;
    private array $configuration;
    private array $data;

    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubService
    ) {
        $this->entityManager = $entityManager;
        $this->githubService = $githubService;
        $this->configuration = [];
        $this->data = [];
    }

    /**
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     *
     * @throws Exception
     */
    public function setRepositoryWithGithubInfo(ObjectEntity $repository, $github): ObjectEntity
    {
        $repository->setValue('source', 'github');
        $repository->setValue('name', $github['name']);
        $repository->setValue('url', $github['url']);
        $repository->setValue('avatar_url', $github['avatar_url']);
        $repository->setValue('last_change', $github['last_change']);
        $repository->setValue('stars', $github['stars']);
        $repository->setValue('fork_count', $github['fork_count']);
        $repository->setValue('issue_open_count', $github['issue_open_count']);
        $repository->setValue('programming_languages', $github['programming_languages']);

        $this->entityManager->persist($repository);

        return $repository;
    }

    /**
     * @param string $repositoryUrl
     * @param Entity $repositoryEntity
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function getOrganisationRepos(string $repositoryUrl, Entity $repositoryEntity): ?ObjectEntity
    {
        $source = null;
        $domain = parse_url($repositoryUrl, PHP_URL_HOST);
        $domain == 'github.com' && $source = 'github';
        $domain == 'gitlab.com' && $source = 'gitlab';

        switch ($source) {
            case 'github':
                // let's get the repository data
                $github = $this->githubService->getRepositoryFromUrl(trim(parse_url($repositoryUrl, PHP_URL_PATH), '/'));

                if ($github !== null) {
                    if (!$this->entityManager->getRepository('App:ObjectEntity')->findByEntity($repositoryEntity, ['url' => $github['url']])) {
                        $repository = new ObjectEntity();
                        $repository->setEntity($repositoryEntity);
                    } else {
                        $repository = $this->entityManager->getRepository('App:ObjectEntity')->findByEntity($repositoryEntity, ['url' => $github['url']])[0];
                    }

                    $repository = $this->setRepositoryWithGithubInfo($repository, $github);

                    $this->entityManager->flush();

                    return $repository;
                }
            case 'gitlab':
                // hetelfde maar dan voor gitlab
            default:
                // error voor onbeknd type
        }

        return null;
    }

    /**
     * @param ObjectEntity $organisation
     * @param Entity       $repositoryEntity
     *
     * @throws GuzzleException
     *
     * @return ObjectEntity|null
     */
    public function enrichRepositoryWithOrganisationRepos(ObjectEntity $organisation, Entity $repositoryEntity): ?ObjectEntity
    {
        $ownsRepositories = [];
        if ($owns = $organisation->getValue('owns')) {
            foreach ($owns as $repositoryUrl) {
                if (!$repository = $this->getOrganisationRepos($repositoryUrl, $repositoryEntity)) {
                    return $organisation;
                }
                $ownsRepositories[] = $repository->getId()->toString();
            }
        }
        $organisation->setValue('owns', $ownsRepositories);

        $usesRepositories = [];
        if ($uses = $organisation->getValue('uses')) {
            foreach ($uses as $repositoryUrl) {
                if (!$repository = $this->getOrganisationRepos($repositoryUrl, $repositoryEntity)) {
                    return $organisation;
                }
                $usesRepositories[] = $repository->getId()->toString();
            }
        }
        $organisation->setValue('uses', $usesRepositories);

        $supportsRepositories = [];
        if ($supports = $organisation->getValue('supports')) {
            foreach ($supports as $repositoryUrl) {
                if (!$repository = $this->getOrganisationRepos($repositoryUrl, $repositoryEntity)) {
                    return $organisation;
                }
                $supportsRepositories[] = $repository->getId()->toString();
            }
        }
        $organisation->setValue('supports', $supportsRepositories);

        $this->entityManager->persist($organisation);
        $this->entityManager->flush();

        return $organisation;
    }

    /**
     * @param array $data          data set at the start of the handler
     * @param array $configuration configuration of the action
     *
     * @throws GuzzleException
     *
     * @return array dataset at the end of the handler
     */
    public function enrichOrganizationWithRepositoriesHandler(array $data, array $configuration): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $repositoryEntity = $this->entityManager->getRepository('App:Entity')->find($this->configuration['repositoryEntityId']);
        // If we want to do it for al repositories
        foreach ($repositoryEntity->getObjectEntities() as $repository) {
            if ($organisation = $repository->getValue('organisation')) {
                if ($organisation instanceof ObjectEntity) {
                    $this->enrichRepositoryWithOrganisationRepos($organisation, $repositoryEntity);
                }
            }
        }

        return $this->data;
    }
}
