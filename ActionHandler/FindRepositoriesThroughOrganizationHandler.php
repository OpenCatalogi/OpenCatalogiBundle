<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\FindRepositoriesThroughOrganizationService;

/**
 * Via de github organisatie de repro's vand eorganisatie ophalen.
 */
class FindRepositoriesThroughOrganizationHandler implements ActionHandlerInterface
{
    private FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService;

    public function __construct(FindRepositoriesThroughOrganizationService $findRepositoriesThroughOrganizationService)
    {
        $this->findRepositoriesThroughOrganizationService = $findRepositoriesThroughOrganizationService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'FindRepositoriesThroughOrganizationHandler',
            'description'=> 'This handler finds repositories through organizations',
            'required'   => ['repositoryEntityId'],
            'properties' => [
                'repositoryEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the repository entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.repository.schema.json',
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->findRepositoriesThroughOrganizationService->findRepositoriesThroughOrganisationHandler($data, $configuration);
    }
}
