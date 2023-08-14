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
class DeveloperOverheidService
{

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
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param LoggerInterface        $pluginLogger    The plugin version of the logger interface.
     * @param GatewayResourceService $resourceService The Gateway Resource Service.
     * @pqram GetResourcesService    $getResourcesService   The Get Resources. Service.
     */
    public function __construct(
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        GetResourcesService $getResourcesService
    ) {
        $this->pluginLogger        = $pluginLogger;
        $this->resourceService     = $resourceService;
        $this->getResourcesService = $getResourcesService;
        $this->data                = [];
        $this->configuration       = [];

    }//end __construct()


    /**
     * Get all components or one component through the products of developer.overheid.nl/apis/{id}.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $componentId   The given component id
     *
     * @return array|null
     */
    public function getComponents(?array $data=[], ?array $configuration=[], ?string $componentId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($source === null
            && $endpoint === null
        ) {
            return $this->data;
        }

        if ($componentId === null) {
            return $this->getResourcesService->getComponents($source, $endpoint, $this->configuration);
        }

        return $this->getResourcesService->getComponent($source, $endpoint, $componentId, $this->configuration);

    }//end getDeveloperOverheidComponents()


    /**
     * Get all repositories or one repository through the repositories of developer.overheid.nl/repositories/{id}.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $repositoryId  The given repository id
     *
     * @return array|null
     */
    public function getRepositories(?array $data=[], ?array $configuration=[], ?string $repositoryId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($source === null
            && $endpoint === null
        ) {
            return $this->data;
        }

        if ($repositoryId === null) {
            return $this->getResourcesService->getRepositories($source, $endpoint, $this->configuration);
        }

        return $this->getResourcesService->getRepository($source, $endpoint, $repositoryId, $this->configuration);

    }//end getDeveloperOverheidRepositories()


}//end class
