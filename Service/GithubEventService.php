<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

class GithubEventService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubService;

    /**
     * @var CheckRepositoriesForPubliccodeService
     */
    private CheckRepositoriesForPubliccodeService $checkRepositoriesForPubliccodeService;

    /**
     * @var FindOrganizationThroughRepositoriesService
     */
    private FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService;

    /**
     * @var FindRepositoriesThroughOrganizationService
     */
    private FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService;

    /**
     * @var RatingService
     */
    private RatingService $ratingService;

    /**
     * @var PubliccodeService
     */
    private PubliccodeService $publiccodeService;

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
    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface                     $entityManager                              EntityManagerInterface
     * @param GithubApiService                           $githubService                              GithubApiService
     * @param CheckRepositoriesForPubliccodeService      $checkRepositoriesForPubliccodeService      CheckRepositoriesForPubliccodeService
     * @param FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService FindOrganizationThroughRepositoriesService
     * @param FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService FindRepositoriesThroughOrganizationService
     * @param RatingService                              $ratingService                              RatingService
     * @param PubliccodeService                          $publiccodeService                          PubliccodeService
     * @param LoggerInterface  $mappingLogger The logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubService,
        CheckRepositoriesForPubliccodeService $checkRepositoriesForPubliccodeService,
        FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService,
        FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService,
        RatingService $ratingService,
        PubliccodeService $publiccodeService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->githubService = $githubService;
        $this->checkRepositoriesForPubliccodeService = $checkRepositoriesForPubliccodeService;
        $this->findOrganizationThroughRepositoriesService = $findOrganizationThroughRepositoriesService;
        $this->findRepositoriesThroughOrganizationService = $findRepositoriesThroughOrganizationService;
        $this->ratingService = $ratingService;
        $this->publiccodeService = $publiccodeService;
        $this->configuration = [];
        $this->data = [];
        $this->logger = $pluginLogger;
    }

    /**
     * @param array $content
     *
     * @throws GuzzleException
     *
     * @return Response dataset at the end of the handler
     */
    public function updateRepositoryWithEventResponse(array $content): Response
    {
        $repositoryEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Repository']);
        $componentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Component']);
        $organisationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Organisation']);
        $descriptionEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Description']);
        $ratingEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['name' => 'Rating']);

        $repositoryName = $content['repository']['name'];

        if (!$this->entityManager->getRepository('App:ObjectEntity')->findByEntity($repositoryEntity, ['name' => $repositoryName])) {
            $repository = new ObjectEntity($repositoryEntity);
        } else {
            $repository = $this->entityManager->getRepository('App:ObjectEntity')->findByEntity($repositoryEntity, ['name' => $repositoryName])[0];
        }

        if ($publiccodeUrl = $repository->getValue('publiccode_url')) {
            if (is_array($publiccode = $this->githubService->getPubliccode($publiccodeUrl))) {
                $this->checkRepositoriesForPubliccodeService->enrichRepositoryWithPubliccode($repository, $componentEntity, $descriptionEntity, $publiccode);
            }
        } elseif ($publiccode = $this->githubService->getPubliccodeForGithubEvent($content['organization']['login'], $content['repository']['name'])) {
            $this->checkRepositoriesForPubliccodeService->enrichRepositoryWithPubliccode($repository, $componentEntity, $descriptionEntity, $publiccode);
        }

        $this->findOrganizationThroughRepositoriesService->enrichRepositoryWithOrganisation($repository, $organisationEntity);

        if ($organisation = $repository->getValue('organisation')) {
            if ($organisation instanceof ObjectEntity) {
                $organisation = $this->findRepositoriesThroughOrganizationService->enrichRepositoryWithOrganisationRepos($organisation, $repositoryEntity);
                $this->publiccodeService->getOrganizationCatalogi($organisation);
            }
        }

        if ($component = $repository->getValue('component')) {
            $this->ratingService->rateComponent($component, $ratingEntity);
        }

        $repository->setValue('name', $content['repository']['name']);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return new Response(json_encode($repository->toArray()), 200, ['content-type' => 'application/json']);
    }
}
