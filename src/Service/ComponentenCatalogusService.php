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
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ComponentenCatalogusService
{

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;

    /**
     * @var GetResourcesService
     */
    private GetResourcesService $getResourcesService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var array
     */
    private array $configuration;


    /**
     * @param GatewayResourceService $resourceService     The Gateway Resource Service.
     * @param LoggerInterface        $pluginLogger        The Plugin logger.
     * @param GetResourcesService    $getResourcesService The Get Resources Service.
     */
    public function __construct(
        GatewayResourceService $resourceService,
        LoggerInterface $pluginLogger,
        GetResourcesService $getResourcesService
    ) {
        $this->pluginLogger        = $pluginLogger;
        $this->resourceService     = $resourceService;
        $this->getResourcesService = $getResourcesService;
        $this->data                = [];
        $this->configuration       = [];

    }//end __construct()


    /**
     * Get all applications or one application through the products of https://componentencatalogus.commonground.nl/api/products.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $applicationId The given application id
     *
     * @return array|null
     */
    public function getComponentenCatalogusApplications(?array $data=[], ?array $configuration=[], ?string $applicationId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($applicationId === null) {
            return $this->getResourcesService->getApplications($source, $endpoint, $this->configuration);
        }

        return $this->getResourcesService->getApplication($source, $endpoint, $applicationId, $this->configuration);

    }//end getComponentenCatalogusApplications()


    /**
     * Get all the components or one component through the components of https://componentencatalogus.commonground.nl/api/components.
     *
     * @param array|null  $data          The data array from the request
     * @param array|null  $configuration The configuration array from the request
     * @param string|null $componentId   The given component id
     *
     * @return array|null
     */
    public function getComponentenCatalogusComponents(?array $data=[], ?array $configuration=[], ?string $componentId=null): ?array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the source and endpoint from the configuration array.
        $source   = $this->resourceService->getSource($this->configuration['source'], 'open-catalogi/open-catalogi-bundle');
        $endpoint = $this->configuration['endpoint'];

        if ($componentId === null) {
            return $this->getResourcesService->getComponents($source, $endpoint, $this->configuration);
        }

        return $this->getResourcesService->getComponent($source, $endpoint, $componentId, $this->configuration);

    }//end getComponentenCatalogusComponents()


}//end class
