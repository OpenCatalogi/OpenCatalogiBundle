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
     * @param LoggerInterface        $pluginLogger          The plugin version of the logger interface.
     * @param ImportResourcesService $importResourceService The Import Resources Service.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        LoggerInterface $pluginLogger,
        ImportResourcesService $importResourceService
    ) {
        $this->entityManager         = $entityManager;
        $this->callService           = $callService;
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
     */
    public function getRepositories(Source $source, string $endpoint, array $configuration): ?array
    {
        $repositories = $this->callService->getAllResults($source, $endpoint);
        $this->pluginLogger->info('Found '.count($repositories).' repositories from '.$source->getName());

        $result = [];
        foreach ($repositories as $repository) {
            $result[] = $this->importResourceService->importRepository($repository, $configuration);
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
     */
    public function getRepository(Source $source, string $endpoint, string $repositoryId, array $configuration): ?array
    {
        $response   = $this->callService->call($source, $endpoint.'/'.$repositoryId);
        $repository = json_decode($response->getBody()->getContents(), true);

        if ($repository === null) {
            $this->pluginLogger->error('Could not find an repository with id: '.$repositoryId.' and with source: '.$source->getName(), ['package' => 'open-catalogi/open-catalogi-bundle']);

            return null;
        }

        $repository = $this->importResourceService->importRepository($repository, $configuration);
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


}//end class
