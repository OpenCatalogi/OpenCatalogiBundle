<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\EnrichPubliccodeService;

/**
 * Haalt publiccode bestanden op.
 */
class EnrichPubliccodeHandler implements ActionHandlerInterface
{

    /**
     * @var EnrichPubliccodeService
     */
    private EnrichPubliccodeService $service;


    /**
     * @param EnrichPubliccodeService $service Thew enrichPubliccodeService
     */
    public function __construct(EnrichPubliccodeService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration()
    {
        return [
            '$id'         => 'https://opencatalogi.nl/ActionHandler/EnrichPubliccodeHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'EnrichPubliccodeHandler',
            'description' => 'This handler checks repositories for publuccode.yaml or publiccode.yml',
            'required'    => [
                'githubSource',
                'repositorySchema',
                'componentSchema',
                'componentMapping',
            ],
            'properties'  => [
                'githubSource'     => [
                    'type'        => 'string',
                    'description' => 'The source of the developer overheid.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'repositorySchema' => [
                    'type'        => 'string',
                    'description' => 'The repository schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true,
                ],
                'componentSchema'  => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'componentMapping' => [
                    'type'        => 'string',
                    'description' => 'The publiccode component mapping.',
                    'example'     => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'required'    => true,
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
        return $this->service->enrichPubliccodeHandler($data, $configuration);

    }//end run()


}//end class
