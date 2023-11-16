<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Gets an organization from the response of the githubEventAction and formInputAction
 * and enriches the organization.
 */
class EnrichOrganizationService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GithubApiService
     */
    private GithubApiService $githubApiService;

    /**
     * @var ImportResourcesService
     */
    private ImportResourcesService $importResourcesService;

    /**
     * @var SynchronizationService $syncService
     */
    private SynchronizationService $syncService;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager          The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger           The plugin version of the logger interface
     * @param GatewayResourceService $resourceService        The Gateway Resource Service.
     * @param GithubApiService       $githubApiService       The Github API Service
     * @param ImportResourcesService $importResourcesService The Import Resources Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService,
        ImportResourcesService $importResourcesService,
        SynchronizationService $syncService
    ) {
        $this->entityManager          = $entityManager;
        $this->pluginLogger           = $pluginLogger;
        $this->resourceService        = $resourceService;
        $this->githubApiService       = $githubApiService;
        $this->importResourcesService = $importResourcesService;
        $this->syncService            = $syncService;

        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param ObjectEntity $organization Catalogi organization https://opencatalogi.nl/oc.organisation.schema.json
     *
     * @throws GuzzleException|Exception
     *
     * @return ObjectEntity
     */
    public function enrichOrganization(ObjectEntity $organization): ObjectEntity
    {
        // Get the github api source.
        $source = $this->resourceService->getSource($this->configuration['githubSource'], 'open-catalogi/open-catalogi-bundle');
        if ($source === null
            || $this->githubApiService->checkGithubAuth($source) === false
        ) {
            return $organization;
        }//end if

        // Get the path of the github url.
        $githubPath = \Safe\parse_url($organization->getValue('github'))['path'];

        // Get the organization from the github api.
        $organizationArray = $this->githubApiService->getOrganization(trim($githubPath, '/'), $source);

        // If we get an empty string we set the description from the github api.
        if ($organization->getValue('description') !== false
            || $organization->getValue('description') !== null
        ) {
            $organization->hydrate(['description' => $organizationArray['description']]);
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
        }

        $this->pluginLogger->debug($organization->getName().' succesfully updated the organization with a description.');

        return $organization;

    }//end enrichOrganization()


    /**
     * Makes sure the action the action can actually runs and then executes functions to update an organization with fetched opencatalogi.yaml info.
     *
     * @param ?array $data          data set at the start of the handler (not needed here)
     * @param ?array $configuration configuration of the action          (not needed here)
     *
     * @throws GuzzleException|Exception
     *
     * @return array|null dataset at the end of the handler              (not needed here)
     */
    public function enrichOrganizationHandler(?array $data=[], ?array $configuration=[], ?string $organizationId=null): ?array
    {
        $this->configuration = $configuration;
        $this->data          = $data;

        // Get the response.
        try {
            $response = json_decode($this->data['response']->getContent(), true);
        } catch (Exception $exception) {
            $this->pluginLogger->error('Cannot get the response.');
        }

        if (isset($response) === false) {
            return $this->data;
        }

        // Get the organization object.
        $organization = $this->entityManager->find('App:ObjectEntity', $response['_self']['id']);

        // Check if the name and github is not null.
        if ($organization instanceof ObjectEntity === true
            && $organization->getValue('name') !== null
            && $organization->getValue('github') !== null
        ) {
            // Enrich the organization object.
            $this->enrichOrganization($organization);
        }//end if

        if ($organization instanceof ObjectEntity === false) {
            $this->pluginLogger->error('Could not find given organization');

            return $this->data;
        }//end if

        return $this->data;

    }//end enrichOrganizationHandler()


}//end class
