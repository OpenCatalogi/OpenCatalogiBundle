# Commonground

Commonground specific properties for use with the [componnenten catalogue](https://componentencatalogus.commonground.nl/).

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Commonground.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| intendedOrganisations | array | A list of organisations that may use this component (wont be visable to other organisations) | No |
| installationType | string | Extension to publiccode bassed on the componentencatalogus. Should be one of
- self
- helm | No |
| layerType | string | An extension to public code based on the componentencatalogus. Refers to the layer on wich the component oprates, see [documentation](https://commonground.nl/cms/view/12f73f0d-ae26-4021-ba52-849eef37d11f/de-common-ground-principes/03743740-a49f-48d8-9fc5-e24f86d748ed) | No |
