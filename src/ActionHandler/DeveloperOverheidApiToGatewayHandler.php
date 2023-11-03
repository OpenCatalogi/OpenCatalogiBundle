<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class DeveloperOverheidApiToGatewayHandler implements ActionHandlerInterface
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/DeveloperOverheidApiToGatewayHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'DeveloperOverheidApiToGatewayHandler',
            'description' => 'This is a action to create objects from the fetched apis from the developer overheid source.',
            'required'    => [
                'source',
                'componentMapping',
                'componentSchema',
                'endpoint',
                'githubSource',
                'repositorySchema',
                'repositoryEndpoint',
                'repositoryMapping'
            ],
            'properties'  => [
                'source'           => [
                    'type'        => 'string',
                    'description' => 'The source of the developer overheid.',
                    'example'     => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'required'    => true,
                ],
                'componentMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for developer overheid apis to oc component.',
                    'example'     => 'https://developer.overheid.nl/api/oc.developerOverheidComponent.mapping.json',
                    'reference'   => 'https://developer.overheid.nl/api/oc.developerOverheidComponent.mapping.json',
                    'required'    => true,
                ],
                'componentSchema'  => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'endpoint'         => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/apis',
                    'required'    => true,
                ],
                'githubSource'           => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'repositorySchema'  => [
                    'type'        => 'string',
                    'description' => 'The repository schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true,
                ],
                'repositoryEndpoint'         => [
                    'type'        => 'string',
                    'description' => 'The endpoint for repositories.',
                    'example'     => '/repositories',
                    'required'    => true,
                ],
                'repositoryMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repository to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the developer overheid components to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->getComponents($data, $configuration);

    }//end run()


}//end class
