<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FederalizationService;

/**
 * Handles the federalisation cron actions for open catalogi. e.g. getting data from other catalogi.
 */
class CatalogiHandler implements ActionHandlerInterface
{
    /**
     * @var FederalizationService
     */
    private FederalizationService $service;

    /**
     * @param FederalizationService $service The federalization Service
     */
    public function __construct(FederalizationService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/CatalogiHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'CatalogiHandler',
            'description' => 'Syncs  all the know catalogi',
            'required'    => [],
            'properties'  => [],
        ];
    }//end getConfiguration()

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
        return $this->service->catalogiHandler($data, $configuration);
    }//end run()
}//end class
