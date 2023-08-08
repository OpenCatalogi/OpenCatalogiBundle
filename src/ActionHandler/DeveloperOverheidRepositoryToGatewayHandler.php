<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class DeveloperOverheidRepositoryToGatewayHandler implements ActionHandlerInterface
{

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $service;


    /**
     * @param DeveloperOverheidService $service The developer Overheid Service
     */
    public function __construct(DeveloperOverheidService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/DeveloperOverheidRepositoryToGatewayHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'DeveloperOverheidRepositoryToGatewayHandler',
            'description' => 'This is a action to create objects from the fetched repositories from the developer overheid source.',
            'required'    => ['source', 'schema', 'endpoint'],
            'properties'  => [
                'source' => [
                    'type'        => 'string',
                    'description' => 'The source of the developer overheid.',
                    'example'     => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'required'    => true
                ],
                'schema' => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true
                ],
                'endpoint' => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/repositories',
                    'required'    => true
                ]
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
        return $this->service->getDeveloperOverheidRepositories($data, $configuration);

    }//end run()


}//end class
