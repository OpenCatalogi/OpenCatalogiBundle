# Localisation

This section provides an overview of the localization features of the software.

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Localisation.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| localisationReady | boolean | If true, the software has infrastructure in place or is otherwise designed to be multilingual. It does not need to be available in more than one language. | Yes |
| availableLanguages | array | If present, this is the list of languages in which the software is available. Of course, this list will contain at least one language. The primary language subtag cannot be omitted, as mandated by the BCP 47.
 | No |
