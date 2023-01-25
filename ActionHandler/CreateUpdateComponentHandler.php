<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;


/**
 * Komt te vervallen
 */
class CreateUpdateComponentHandler implements ActionHandlerInterface
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
            '$id'        => 'https://opencatalogi.nl/oc.component.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'CreateUpdateComponentHandler',
            'description'=> 'This is a handler to create or update a component.',
            'required'   => ['source', 'entity', 'location', 'componentsEntity', 'componentsLocation'],
            'properties' => [
                'source' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the github usercontent source',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                ],
                'entity' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the Catalogi entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.catalogi.schema.json'
                ],
                'location' => [
                    'type'        => 'string',
                    'description' => 'The location where we can find Catalogi',
                    'example'     => '/api/oc/catalogi',
                    'required'    => true,
                ],
                'componentsEntity' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the Component entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.component.schema.json'
                ],
                'componentsLocation' => [
                    'type'        => 'string',
                    'description' => 'The location where we can find Component',
                    'example'     => '/api/oc/components',
                    'required'    => true,
                ],
            ]
        ];
    }

    /**
     * This function runs the email service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->catalogiService->createUpdateComponentHandler($data, $configuration);
    }
}
