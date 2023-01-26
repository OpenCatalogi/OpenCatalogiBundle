<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  OpenCatalogi\OpenCatalogiBundle\Service\FindOrganizationThroughRepositoriesService;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

class FindOrganizationThroughRepositoriesHandler implements ActionHandlerInterface
{
    private FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService;

    public function __construct(FindOrganizationThroughRepositoriesService $findOrganizationThroughRepositoriesService)
    {
        $this->findOrganizationThroughRepositoriesService = $findOrganizationThroughRepositoriesService;
    }

    public function getConfiguration()
    {
        return [
            '$id'        => 'https://example.com/person.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'PubliccodeFindOrganizationThroughRepositoriesHandler',
            'description'=> 'This handler finds organizations through repositories',
            'required'   => ['repositoryEntityId', 'organisationEntityId'],
            'properties' => [
                'repositoryEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the repository entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.repository.schema.json'
                ],
                'organisationEntityId' => [
                    'type'        => 'uuid',
                    'description' => 'The uuid of the organisation entity',
                    'example'     => 'b484ba0b-0fb7-4007-a303-1ead3ab48846',
                    'required'    => true,
                    '$ref'        => 'https://opencatalogi.nl/oc.organisation.schema.json'
                ],
            ],
        ];
    }

    public function run(array $data, array $configuration): array
    {
        return $this->findOrganizationThroughRepositoriesService->findOrganizationThroughRepositoriesHandler($data, $configuration);
    }
}
