<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\RatingService;

/**
 * Berkent de rating van het component.
 */
class RatingHandler implements ActionHandlerInterface
{
    /**
     * @var RatingService
     */
    private RatingService $service;

    /**
     * @param RatingService $service The RatingService
     */
    public function __construct(RatingService $service)
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
            '$id'        => 'https://opencatalogi.nl/ActionHandler/RatingHandler.ActionHandler.json',
            '$schema'    => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'      => 'RatingHandler',
            'description'=> 'This handler sets the rating of a component',
            'required'   => [],
            'properties' => [],
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
        return $this->service->ratingHandler($data, $configuration);
    }//end run()
}//end class
