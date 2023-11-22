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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager Interface
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GithubApiService $githubApiService
    ) {
        $this->entityManager    = $entityManager;
        $this->pluginLogger     = $pluginLogger;
        $this->resourceService  = $resourceService;
        $this->githubApiService = $githubApiService;

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

        if ($organization->getValue('type') === 'Organization') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getOrganization(trim($githubPath, '/'), $source);
        }

        if ($organization->getValue('type') === 'User') {
            // Get the organization from the github api.
            $organizationArray = $this->githubApiService->getUser(trim($githubPath, '/'), $source);
        }

        $opencatalogiUrl = $organization->getValue('opencatalogiRepo');
        if ($opencatalogiUrl === null) {
            return $organization;
        }

        $path            = trim(\Safe\parse_url($opencatalogiUrl)['path'], '/');

        // Call the search/code endpoint for publiccode files in this repository.
        $queryConfig['query'] = ['q' => "filename:opencatalogi extension:yaml extension:yml repo:{$path}"];
        $opencatalogiFiles    = $this->githubApiService->getFilesFromRepo($source, $queryConfig);

        $opencatalogiNames = [
            'opencatalogi.yaml',
            'opencatalogi.yml',
        ];

        foreach ($opencatalogiFiles as $item) {
            if (in_array($item['name'], $opencatalogiNames) === true) {
                $organization = $this->githubApiService->handleOpencatalogiFile($organizationArray, $source, $item);

                break;
            }
        }

        $this->pluginLogger->debug($organization->getValue('name').' succesfully updated the organization with the opencatalogi file.');

        return $organization;

    }//end enrichOrganization()


    /**
     * This function gets all the repositories from the given organization and sets it to the owns of the organization.
     *
     * @param string $organizationId The id of the organization in the response.
     *
     * @throws GuzzleException|Exception
     *
     * @return array
     */
    public function getOrganization(string $organizationId): array
    {
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
        $organization = $this->entityManager->find('App:ObjectEntity', $organizationId);

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

    }//end getOrganization()


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

        // If there is an organization in the response.
        if ($organizationId !== null) {
            $this->getOrganization($organizationId);
        }

        // If there is no organization we get all the organizations and enrich it.
        if ($organizationId === null) {
            $organizationSchema = $this->resourceService->getSchema($this->configuration['organizationSchema'], 'open-catalogi/open-catalogi-bundle');
            $organizations = $this->entityManager->getRepository('App:ObjectEntity')->findBy(['entity' => $organizationSchema]);

            foreach ($organizations as $organization) {
                // Check if the name and github is not null.
                if ($organization instanceof ObjectEntity === true
                    && $organization->getValue('name') !== null
                    && $organization->getValue('github') !== null
                ) {
                    // Enrich the organization object.
                    $this->enrichOrganization($organization);
                }//end if
            }
        }

        return $this->data;

    }//end enrichOrganizationHandler()


}//end class
