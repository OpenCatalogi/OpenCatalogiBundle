# Legal

This section provides information on the legalstatus of the software, useful to evaluate whether the software can be used or not.

In the following part of the document, all keys are assumed to be in a sub-section with the name of the language (we will note this with [lang]).

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Legal.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| license | string | This string describes the license under which the software is distributed. The string must contain a valid SPDX expression, referring to one (or multiple) open-source license. Please refer to the SPDX documentation for further information. | No |
| mainCopyrightOwner | Any | N/A | No |
| repoOwner | Any | N/A | No |
| authorsFile | string | Some open-source software adopt a convention of identify the copyright holders through a file that lists all the entities that own the copyright. This is common in projects strongly backed by a community where there are many external contributors and no clear single/main copyright owner. In such cases, this key can be used to refer to the authors file, using a path relative to the root of the repository. | No |
