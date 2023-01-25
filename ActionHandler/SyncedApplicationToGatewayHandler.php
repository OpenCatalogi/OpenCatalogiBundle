<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

class SyncedApplicationToGatewayHandler implements ActionHandlerInterface
{
    private CatalogiService $catalogiService;

    public function __construct(CatalogiService $catalogiService)
    {
        $this->catalogiService = $catalogiService;
    }

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {

        return [
            '$id'        => 'https://opencatalogi.nl/oc.application.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'SyncedApplicationToGatewayHandler',
            'description'=> 'This is a action to create objects from the fetched application.',
            'required'   => ['source', 'applicationEntity'],
            'properties' => [
                'source' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the componenten catalogus source',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                ],
                'applicationEntity' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the Application entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.application.schema.json'
                ],
            ]
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
        return $this->catalogiService->syncedApplicationToGatewayHandler($data, $configuration);
    }
}
