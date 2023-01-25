<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;

class FindOrganizationThroughRepositoriesService
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
     * @param ObjectEntity $repository the repository where we want to find an organisation for
     *
     * @throws Exception
     * @throws GuzzleException
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository, Entity $organisationEntity): ?ObjectEntity
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

        switch ($source) {
            case 'github':
                // let's get the repository data
                $github = $this->githubService->getRepositoryFromUrl(trim(parse_url($url, PHP_URL_PATH), '/'));
                if ($github !== null && array_key_exists('organisation', $github) && $github['organisation'] !== null) {
                    $repository = $this->setRepositoryWithGithubInfo($repository, $github);

                    if (!$this->entityManager->getRepository('App:ObjectEntity')->findByEntity($organisationEntity, ['github' => $github['organisation']['github']])) {
                        $organisation = new ObjectEntity();
                        $organisation->setEntity($organisationEntity);
                    } else {
                        $organisation = $this->entityManager->getRepository('App:ObjectEntity')->findByEntity($organisationEntity, ['github' => $github['organisation']['github']])[0];
                    }

                    $organisation->setValue('owns', $github['organisation']['owns']);
                    $organisation->hydrate($github['organisation']);
                    $repository->setValue('organisation', $organisation);
                    $this->entityManager->persist($organisation);
                    $this->entityManager->persist($repository);
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
     * @param array $data          data set at the start of the handler
     * @param array $configuration configuration of the action
     *
     * @throws GuzzleException
     *
     * @return array dataset at the end of the handler
     */
    public function enrichRepositoryWithOrganizationHandler(array $data, array $configuration): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $repositoryEntity = $this->entityManager->getRepository('App:Entity')->find($this->configuration['repositoryEntityId']);
        $organisationEntity = $this->entityManager->getRepository('App:Entity')->find($this->configuration['organisationEntityId']);

        // If we want to do it for al repositories
        foreach ($repositoryEntity->getObjectEntities() as $repository) {
            $this->enrichRepositoryWithOrganisation($repository, $organisationEntity);
        }

        return $this->data;
    }

}
