<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationiService;

/**
 * Handles the federalisation cron actions for open catalogi. e.g. getting data from other catalogi
 */
class CatalogiHandler implements ActionHandlerInterface
{
    private FederalizationiService $federalizationiService;

    public function __construct(FederalizationiService $federalizationiService)
    {
        $this->federalizationiService = $federalizationiService;
    }

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {

        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'CatalogiHandler',
            'description' => 'Syncs  all the know catalogi'
            ];

        // We don't need all of this

        /*
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'CatalogiHandler',
            'required'   => ['entity', 'location', 'componentsEntity', 'componentsLocation'],
            'properties' => [
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
                    'description' => 'The location where we can find Components',
                    'example'     => '/api/oc/components',
                    'required'    => true,
                ],
            ],
        ];

        */
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
        return $this->federalizationiService->catalogiHandler($data, $configuration);
    }
}
