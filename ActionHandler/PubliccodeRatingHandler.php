<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  OpenCatalogi\OpenCatalogiBundle\Service\PubliccodeService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

class PubliccodeRatingHandler implements ActionHandlerInterface
{
    private PubliccodeService $publiccodeService;

    public function __construct(PubliccodeService $publiccodeService)
    {
        $this->publiccodeService = $publiccodeService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'PubliccodeRatingHandler',
            'description'=> 'This handler sets the rating of a component',
            'required'   => ['componentEntityId', 'ratingEntityId'],
            'properties' => [
                'componentEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the component entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/catalogi.schema.json'
                ],
                'ratingEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the rating entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/rating.schema.json'
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->publiccodeService->enrichComponentWithRating($data, $configuration);
    }
}
