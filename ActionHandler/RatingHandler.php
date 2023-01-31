<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\RatingService;

/**
 * Berkent de rating van het component.
 */
class RatingHandler implements ActionHandlerInterface
{
    private RatingService $ratingService;

    public function __construct(RatingService $ratingService)
    {
        $this->ratingService = $ratingService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://opencatalogi.nl/oc.rating.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'RatingHandler',
            'description'=> 'This handler sets the rating of a component',
            'required'   => ['componentEntityId', 'ratingEntityId'],
            'properties' => [
                'componentEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the component entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.component.schema.json',
                ],
                'ratingEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the rating entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.rating.schema.json',
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->ratingService->ratingHandler($data, $configuration);
    }
}
