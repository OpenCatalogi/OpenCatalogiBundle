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
    private DeveloperOverheidService $developerOverheidService;

    /**
     * @param DeveloperOverheidService $developerOverheidService The developer Overheid Service
     */
    public function __construct(DeveloperOverheidService $developerOverheidService)
    {
        $this->developerOverheidService = $developerOverheidService;
    }//end __construct()

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.developeroverheid.api.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'DeveloperOverheidApiToGatewayHandler',
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
        return $this->developerOverheidService->getComponents();
    }//end run()
}//end class
