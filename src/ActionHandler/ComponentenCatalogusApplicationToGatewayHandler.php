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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/ComponentenCatalogusApplicationToGatewayHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ComponentenCatalogusApplicationToGatewayHandler',
            'description' => 'This is a action to create objects from the fetched applications from the componenten catalogus source.',
            'required'    => ['source', 'applicationMapping', 'applicationSchema', 'endpoint', 'componentMapping', 'componentSchema'],
            'properties'  => [
                'source' => [
                    'type'        => 'string',
                    'description' => 'The source of the componenten catalogus.',
                    'example'     => 'https://opencatalogi.nl/source/oc.componentencatalogus.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.componentencatalogus.source.json',
                    'required'    => true
                ],
                'applicationMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for componenten catalogus application to oc application.',
                    'example'     => 'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json',
                    'reference'   => 'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusApplication.mapping.json',
                    'required'    => true
                ],
                'applicationSchema' => [
                    'type'        => 'string',
                    'description' => 'The application schema.',
                    'example'     => 'https://opencatalogi.nl/oc.application.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.application.schema.json',
                    'required'    => true
                ],
                'endpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint for the source.',
                    'example'     => '/products',
                    'required'    => true
                ],
                'componentMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for componenten catalogus component to oc component.',
                    'example'     => 'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json',
                    'reference'   => 'https://componentencatalogus.commonground.nl/api/oc.componentenCatalogusComponent.mapping.json',
                    'required'    => true
                ],
                'componentSchema' => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true
                ],
            ],
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
        return $this->service->getComponentenCatalogusApplications($data, $configuration);

    }//end run()


}//end class
