<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/**
 *  This class handles the interaction with developer.overheid.nl.
 */
class GetResourcesService
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var ImportResourcesService
     */
    private ImportResourcesService $importResourceService;


    /**
     * @param EntityManagerInterface $entityManager         The Entity Manager Interface.
     * @param CallService            $callService           The Call Service.
     * @param GatewayResourceService $resourceService       The Gateway Resource Service.
     * @param SynchronizationService $syncService           The Synchronisation Service.
     * @param LoggerInterface        $pluginLogger          The plugin version of the logger interface.
     * @param ImportResourcesService $importResourceService The Import Resources Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        GatewayResourceService $resourceService,
        SynchronizationService $syncService,
        LoggerInterface $pluginLogger,
        ImportResourcesService $importResourceService
    ) {
        $this->entityManager         = $entityManager;
        $this->callService           = $callService;
        $this->syncService           = $syncService;
        $this->resourceService       = $resourceService;
        $this->pluginLogger          = $pluginLogger;
        $this->importResourceService = $importResourceService;

    }//end __construct()


    /**
     * Get all components of the given source.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param array  $configuration The configuration array
     *
     * @return array|null
     */
    public function getComponents(Source $source, string $endpoint, array $configuration): ?array
    {
        $components = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($components).' components from '.$source->getName());

        $result = [];
        foreach ($components as $component) {
            $result[] = $this->importResourceService->importComponent($component, $configuration);
        }

        $this->entityManager->flush();

        return $result;

    }//end getComponents()


    /**
     * Get a component of the given source with the given id.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param string $componentId   The given component id
     * @param array  $configuration The configuration array
     *
     * @return array|null
     */
    public function getComponent(Source $source, string $endpoint, string $componentId, array $configuration): ?array
    {
        $response  = $this->callService->call($source, $endpoint.'/'.$componentId);
        $component = json_decode($response->getBody()->getContents(), true);

        if ($component === null) {
            $this->pluginLogger->error('Could not find an component with id: '.$componentId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $component = $this->importResourceService->importComponent($component, $configuration);
        if ($component === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found component with id: '.$componentId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $component->toArray();

    }//end getComponent()


    /**
     * Get all repositories of the given source.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param array  $configuration The configuration array
     *
     * @return array|null
     * @throws \Exception
     */
    public function getRepositories(Source $source, string $endpoint, array $configuration): ?array
    {
        $repositories = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($repositories).' repositories from '.$source->getName());

        $result = [];
        foreach ($repositories as $repository) {
            $result[] = $this->importResourceService->importDevRepository($repository, $configuration);
        }

        $this->entityManager->flush();

        return $result;

    }//end getRepositories()


    /**
     * Get a repository of the given source with the given id.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param string $repositoryId  The given repository id
     * @param array  $configuration The configuration array
     *
     * @return array|null
     * @throws \Exception
     */
    public function getRepository(Source $source, string $endpoint, string $repositoryId, array $configuration): ?array
    {
        $response   = $this->callService->call($source, $endpoint.'/'.$repositoryId);
        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find an repository with id: '.$repositoryId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $repository = $this->importResourceService->importDevRepository($repository, $configuration);
        if ($repository === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found repository with id: '.$repositoryId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $repository->toArray();

    }//end getRepository()


    /**
     * Get all applications of the given source.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param array  $configuration The configuration array
     *
     * @return array|null
     */
    public function getApplications(Source $source, string $endpoint, array $configuration): ?array
    {
        $applications = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($applications).' applications from '.$source->getName());

        $result = [];
        foreach ($applications as $application) {
            $result[] = $this->importResourceService->importApplication($application, $configuration);
        }

        $this->entityManager->flush();

        return $result;

    }//end getApplications()


    /**
     * Get an applications of the given source with the given id.
     *
     * @param Source $source        The given source
     * @param string $endpoint      The endpoint of the source
     * @param string $applicationId The given application id
     * @param array  $configuration The configuration array
     *
     * @return array|null
     */
    public function getApplication(Source $source, string $endpoint, string $applicationId, array $configuration): ?array
    {
        $response    = $this->callService->call($source, $endpoint.'/'.$applicationId);
        $application = json_decode($response->getBody()->getContents(), true);

        if ($application === null) {
            $this->pluginLogger->error('Could not find an application with id: '.$applicationId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $application = $this->importResourceService->importApplication($application, $configuration);
        if ($application === null) {
            return null;
        }

        $this->entityManager->flush();

        $this->pluginLogger->info('Found application with id: '.$applicationId, ['package' => 'open-catalogi/open-catalogi-bundle']);

        return $application->toArray();

    }//end getApplication()


    /**
     * This function fetches repository data.
     *
     * @param Source $source The github source
     * @param string $slug   endpoint to request
     *
     * @return array|null
     */
    public function getRepositoryFromUrl(Source $source, string $slug): ?array
    {
        try {
            $response = $this->callService->call($source, '/repos/'.$slug);
        } catch (ClientException | Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch /repos/'.$slug.' '.$e->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === true) {
            $repository = $this->callService->decodeResponse($source, $response, 'application/json');
            $this->pluginLogger->info("Fetch and decode went succesfull for /repos/$slug", ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return $repository;
        }//end if

        return null;

    }//end getRepositoryFromUrl()


    /**
     * Get an organisation from https://api.github.com/orgs/{org}.
     *
     * @param Source $source        The github source
     * @param string $name          The name of the organisation
     * @param array  $configuration The configuration array
     *
     * @return ObjectEntity|null
     */
    public function getOrganisation(Source $source, string $name, array $configuration): ?ObjectEntity
    {
        $this->pluginLogger->info('Getting organisation '.$name, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        try {
            $response = $this->callService->call($source, '/orgs/'.$name);
        } catch (Exception $e) {
            $this->pluginLogger->error('Error found trying to fetch /orgs/'.$name.' '.$e->getMessage(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }

        if (isset($response) === true) {
            $organisation = json_decode($response->getBody()->getContents(), true);
            $this->pluginLogger->info("Fetch and decode went succesfull for /orgs/$name", ['plugin' => 'open-catalogi/open-catalogi-bundle']);
        }//end if

        if (isset($organisation) === false) {
            $this->pluginLogger->error('Could not find an organisation with name: '.$name.' and with source: '.$source->getName(), ['plugin' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }//end if

        $organisation = $this->importResourceService->importOrganisation($organisation, $configuration);
        if ($organisation === null) {
            return null;
        }//end if

        $this->entityManager->flush();

        $this->pluginLogger->debug('Found organisation with name: '.$name, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $organisation;

    }//end getOrganisation()


    /**
     * Get an organisation from https://api.github.com/orgs/{org}/repos.
     *
     * @param  Source $source        The github source
     * @param  string $name          The name of the organisation
     * @param  array  $configuration The configuration array
     * @return array|null
     * @throws \Exception
     */
    public function getOrganisationRepos(Source $source, string $name, array $configuration): ?array
    {
        $componentSchema = $this->resourceService->getSchema('https://opencatalogi.nl/oc.component.schema.json', 'open-catalogi/open-catalogi-bundle');

        $this->pluginLogger->info('Getting repos from organisation '.$name, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        $repositories = $this->callService->getAllResults($source, '/orgs/'.$name.'/repos');

        if ($repositories === null) {
            $this->pluginLogger->error('Could not find a repos from organisation with name: '.$name.' and with source: '.$source->getName());

            return null;
        }//end if

        $data = [];
        foreach ($repositories as $repository) {
            // Import the github repository.
            $repositoryObject = $this->importResourceService->importGithubRepository($repository, $configuration);

            $sync = $this->syncService->findSyncBySource($source, $componentSchema, $repositoryObject->getValue('url'));
            $this->syncService->synchronize($sync, ['name' => $repositoryObject->getValue('name'), 'url' => $repositoryObject]);

            if ($repositoryObject instanceof ObjectEntity === false) {
                continue;
            }

            // Get the connected component and set it to the owns array.
            if ($repositoryObject->getValue('components')->count() === 0) {
                continue;
            }//end if

            foreach ($repositoryObject->getValue('components') as $component) {
                $data[] = $component;
            }
        }//end foreach

        $this->pluginLogger->debug('Found '.count($data).' repos from organisation with name: '.$name, ['plugin' => 'open-catalogi/open-catalogi-bundle']);

        return $data;

    }//end getOrganisationRepos()


}//end class
