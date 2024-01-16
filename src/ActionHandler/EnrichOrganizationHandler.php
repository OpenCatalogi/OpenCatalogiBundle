<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use  CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\EnrichOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\FindGithubRepositoryThroughOrganizationService;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubApiService;

/**
 * Executes a the EnrichOrganizationHandler and gets an organization from the response of the githubEventAction and formInputAction
 * and enriches the organization.
 */
class EnrichOrganizationHandler implements ActionHandlerInterface
{

    /**
     * @var EnrichOrganizationService
     */
    private EnrichOrganizationService $service;


    /**
     * @param EnrichOrganizationService $service The EnrichOrganizationService
     */
    public function __construct(EnrichOrganizationService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration()
    {
        return [
            '$id'         => 'https://opencatalogi.nl/ActionHandler/EnrichOrganizationHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'EnrichOrganizationHandler',
            'description' => 'This handler enriches the organizations',
            'required'    => [
                'githubSource',
                'gitlabSource',
                'usercontentSource',
                'repositorySchema',
                'repositoryMapping',
                'organizationSchema',
                'organizationMapping',
                'componentSchema',
                'publiccodeMapping',
                'opencatalogiMapping',
                'applicationSchema',
                'ratingSchema',
                'ratingMapping'
            ],
            'properties'  => [
                'githubSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'gitlabSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the gitlab api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitlabAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitlabAPI.source.json',
                    'required'    => true,
                ],
                'usercontentSource'   => [
                    'type'        => 'string',
                    'description' => 'The source of the raw user content.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
                    'required'    => true,
                ],
                'repositorySchema'    => [
                    'type'        => 'string',
                    'description' => 'The repository schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true,
                ],
                'repositoryMapping'   => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repository to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'required'    => true,
                ],
                'organizationSchema'  => [
                    'type'        => 'string',
                    'description' => 'The organization schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'required'    => true,
                ],
                'organizationMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github organization to oc organization.',
                    'example'     => 'https://api.github.com/oc.githubOrganization.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOrganization.mapping.json',
                    'required'    => true,
                ],
                'componentSchema'     => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'publiccodeMapping'   => [
                    'type'        => 'string',
                    'description' => 'The mapping for publiccode file to oc component.',
                    'example'     => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'required'    => true,
                ],
                'opencatalogiMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for opencatalogi file to oc organization.',
                    'example'     => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'required'    => true,
                ],
                'applicationSchema'   => [
                    'type'        => 'string',
                    'description' => 'The application schema.',
                    'example'     => 'https://opencatalogi.nl/oc.application.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.application.schema.json',
                    'required'    => true,
                ],
                'ratingSchema'    => [
                    'type'        => 'string',
                    'description' => 'The rating schema.',
                    'example'     => 'https://opencatalogi.nl/oc.rating.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.rating.schema.json',
                    'required'    => true,
                ],
                'ratingMapping'   => [
                    'type'        => 'string',
                    'description' => 'The rating mapping.',
                    'example'     => 'https://opencatalogi.nl/api/oc.rateComponent.mapping.json',
                    'reference'   => 'https://opencatalogi.nl/api/oc.rateComponent.mapping.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the enrich organization service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        $this->data = $data;

        try {
            $this->data['response'] = \Safe\json_decode($data['response']->getContent(), true);
        } catch (\Exception $exception) {
            //
        }

        // This comes from the GithubEvent or FormInput action.
        // We hava an organization in the response.
        $organizationId = null;
        if (key_exists('response', $this->data) === true
            && key_exists('_self', $this->data['response']) === true
            && key_exists('id', $this->data['response']['_self']) === true
        ) {
            $organizationId = $this->data['response']['_self']['id'];
        }//end if


        return $this->service->enrichOrganizationHandler($this->data, $configuration, $organizationId);

    }//end run()


}//end class
