<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\ComponentenCatalogusService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class ComponentenCatalogusComponentToGatewayHandler implements ActionHandlerInterface
{
    /**
     * @var ComponentenCatalogusService
     */
    private ComponentenCatalogusService $compCatService;

    /**
     * @param ComponentenCatalogusService $compCatService ComponentenCatalogusService
     */
    public function __construct(ComponentenCatalogusService $compCatService)
    {
        $this->compCatService = $compCatService;
    }//end __construct()

    /**
     * This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.componentencatalogus.component.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'ComponentenCatalogusComponentToGatewayHandler',
            'description'=> 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'required'   => [],
            'properties' => [],
        ];
    }//end getConfiguration()

    /**
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->compCatService->getComponents();
    }//end run()
}
