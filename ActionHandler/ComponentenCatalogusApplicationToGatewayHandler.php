<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\ComponentenCatalogusService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class ComponentenCatalogusApplicationToGatewayHandler implements ActionHandlerInterface
{
    /**
     * @var ComponentenCatalogusService
     */
    private ComponentenCatalogusService $service;

    /**
     * @param ComponentenCatalogusService $service The componenten Catalogus Service
     */
    public function __construct(ComponentenCatalogusService $service)
    {
        $this->service = $service;
    }//end __construct()

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'        => 'https://opencatalogi.nl/ActionHandler/ComponentenCatalogusApplicationToGatewayHandler.ActionHandler.json',
            '$schema'    => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'      => 'ComponentenCatalogusApplicationToGatewayHandler',
            'description'=> 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'required'   => [],
            'properties' => [],
        ];
    }

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
        return $this->service->getApplications();
    }//end run()
}//end class
