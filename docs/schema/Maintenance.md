# Maintenance

This section provides information on the maintenance status of the software, useful to evaluate whether the software is actively developed or not.

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Maintenance.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| type | string | This key describes how the software is currently maintained.

internal - means that the software is internally maintained by the repository owner;

contract - means that there is a commercial contract that binds an entity to the maintenance of the software;

community - means that the software is currently maintained by one or more people that donate their time to the project;

none - means that the software is not actively maintained. | Yes |
| contractors | array | This key describes the entity or entities, if any, that are currently contracted for maintaining the software. They can be companies, organizations, or other collective names. | No |
| contacts | array | One or more contacts maintaining this software.

This key describes the technical people currently responsible for maintaining the software. All contacts need to be a physical person, not a company or an organisation. If somebody is acting as a representative of an institution, it must be listed within the affiliation of the contact.

In case of a commercial agreement (or a chain of such agreements), specify the final entities actually contracted to deliver the maintenance. Do not specify the software owner unless it is technically involved with the maintenance of the product as well. | No |
