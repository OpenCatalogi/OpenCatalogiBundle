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
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GetResourcesService
     */
    private GetResourcesService $getResourcesService;


    /**
     * @param EntityManagerInterface  $entityManager    The entity manager
     * @param LoggerInterface         $pluginLogger     The plugin version of the logger interface
     * @param GatewayResourceService  $resourceService  The Gateway Resource Service.
     * @param GetResourcesService $getResourcesService The Get Resource Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GetResourcesService $getResourcesService
    ) {
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->getResourcesService = $getResourcesService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * @param ObjectEntity $repository           the repository where we want to find an organisation for
     * @param array        $createdOrganizations the already created organizations during a parent loop so we dont waste time/performance on the same organizations
     *
     * @return ObjectEntity|null
     * @throws Exception
     */
    public function enrichRepositoryWithOrganisation(ObjectEntity $repository, array &$createdOrganizations=[]): ?ObjectEntity
    {
        if ($repository->getValue('url') === null) {
            $this->pluginLogger->error('Repository url not set', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        $source = $repository->getValue('source');
        $url    = $repository->getValue('url');

        if ($source == null) {
            $domain                            = \Safe\parse_url($url, PHP_URL_HOST);
            $domain == 'github.com' && $source = 'github';
            $domain == 'gitlab.com' && $source = 'gitlab';
        }//end if

        $name = trim(\Safe\parse_url($url, PHP_URL_PATH), '/');

        switch ($source) {
        case 'github':
            // let's get the repository datar
            $this->pluginLogger->info("Trying to fetch repository from: $url", ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            // Make sync object.
            $sourceObject = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
            if ($sourceObject === null) {
                return null;
            }

            $githubRepository = $this->getResourcesService->getRepositoryFromUrl($sourceObject, $name);

            // Check if we didnt already loop through this organization during this loop
            if (isset($githubRepository['owner']['login']) === true
                && in_array($githubRepository['owner']['login'], $createdOrganizations) === true
            ) {
                $this->pluginLogger->info('Organization already created/updated during this loop, continuing.', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

                return null;
            }//end if

            if ($githubRepository['owner']['type'] === 'Organization') {
                // get organisation from github and set the property
                $organisation = $this->getResourcesService->getOrganisation($sourceObject, $githubRepository['owner']['login'], $this->configuration);

                $repository->setValue('organisation', $organisation);
                $this->entityManager->persist($repository);

                // get organisation component and set the property
                if (($owns = $this->getResourcesService->getOrganisationRepos($sourceObject, $githubRepository['owner']['login'], $this->configuration)) !== null) {
                    $organisation->setValue('owns', $owns);
                }

                $this->entityManager->persist($organisation);
                $this->entityManager->flush();

                $createdOrganizations[] = $githubRepository['owner']['login'];
            } else {
                $this->pluginLogger->error('No organisation found for fetched repository', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
            break;
        case 'gitlab':
            // hetzelfde maar dan voor gitlab
            // @TODO code for gitlab as we do for github repositories
            $this->pluginLogger->error("We dont do gitlab yet ($url)", ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            break;
        default:
            $this->pluginLogger->error("We dont know this type source yet ($domain)", ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            break;
        }//end switch

        if (isset($repository) === true) {
            $this->entityManager->persist($repository);
        }

        $this->entityManager->flush();

        return null;

    }//end enrichRepositoryWithOrganisation()


    /**
     * Makes sure the action the action can actually runs and then executes functions to update a repository with fetched organization info.
     *
     * @param ?array      $data          data set at the start of the handler (not needed here)
     * @param ?array      $configuration configuration of the action          (not needed here)
     * @param string|null $repositoryId  optional repository id for testing for a single repository
     *
     * @throws GuzzleException|LoaderError|SyntaxError|Exception
     *
     * @return array dataset at the end of the handler                   (not needed here)
     */
    public function findOrganizationThroughRepositoriesHandler(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        if ($repositoryId !== null) {
            // If we are testing for one repository
            if (($repository = $this->entityManager->find('App:ObjectEntity', $repositoryId)) !== null) {
                $this->enrichRepositoryWithOrganisation($repository);
            }

            if ($repository === null) {
                $this->pluginLogger->error('Could not find given repository', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
            }
        }

        $repositorySchema = $this->resourceService->getSchema($this->configuration['repositorySchema'], 'open-catalogi/open-catalogi-bundle');

        // If we want to do it for al repositories
        $this->pluginLogger->info('Looping through repositories', ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        $createdOrganizations = [];
        foreach ($repositorySchema->getObjectEntities() as $repository) {
            $this->enrichRepositoryWithOrganisation($repository, $createdOrganizations);
        }

        $this->pluginLogger->debug('findOrganizationThroughRepositoriesHandler finished', ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $this->data;

    }//end findOrganizationThroughRepositoriesHandler()


}//end class
