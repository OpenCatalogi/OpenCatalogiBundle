# Description

No description available.

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Description.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| id | string | N/A | No |
| localisedName | string | This key is an opportunity to localise the name in a specific language. It contains the (short) public name of the product. It should be the name most people usually refer to the software. In case the software has both an internal “code” name and a commercial name, use the commercial name. | No |
| shortDescription | string | This key contains a short description of the software. It should be a single line containing a single sentence. Maximum 150 characters are allowed. | No |
| longDescription | string | This key contains a longer description of the software, between 150 and 10000 chars. It is meant to provide an overview of the capabilities of the software for a potential user. The audience for this text should be that of users of the software, not developers. You can think of this text as the description of the software that would be in its website (if the software had one).

This description can contain some basic markdown: *italic*, **bold**, bullet points and [links](#). | No |
| documentation | string | This key contains a reference to the user-level (not developer-level) documentation of the software. The value must be a URL to a hosted version of the documentation.

It is suggested that the URL points to a hosted version of the documentation that is immediately readable through a common web browser in both desktop and mobile format. The documentation should be rendered in HTML and browsable like a website (with a navigation index, a search bar, etc.).

If the documentation is instead available only as a document, put a direct view/download link as URL in this key. You should commit the document as part of the source code repository, and then link to it using the code hosting source browser URL (e.g.: GitHub URL to the file). Prefer using open formats like PDF or ODT for maximum interoperability.

Whichever the format for the documentation, remember to make its source files available under an open license, possibly by committing them as part of the repository itself. | No |
| apiDocumentation | string | This key contains a reference to the API documentation of the software. The value must be a URL to a hosted version of the documentation.

It is suggested that the URL points to a hosted version of the documentation that is immediately readable through a common web browser. The documentation should be rendered in HTML and browsable like a website (with a navigation index, a search bar, etc.), and if there is a reference or test deployment, possibly offer an interactive interface (e.g. Swagger).

If the documentation is instead available only as a document, put a direct view/download link as URL in this key. You should commit the document as part of the source code repository, and then link to it using the code hosting source browser URL (e.g.: GitHub URL to the file). Prefer using open formats like PDF or ODT for maximum interoperability.

Whichever the format for the documentation, remember to make its source files available under an open license, possibly by committing them as part of the repository itself. | No |
| features | array | This key contains a list of software features, describing what capabilities the software allows to do. The audience for this text should be that of public decision makers who will be commissioning the software. The features should thus not target developers; instead of listing technical features referring to implementation details, prefer listing user-visible functionalities of the software.

While the key is mandatory, there is no mandatory minimum or maximum number of features that should be listed in this key.

The suggested number of features to list is between 5 and 20, depending on the software size and complexity. There is no need for exhaustiveness, as users can always read the documentation for additional information. | Yes |
| screenshots | array | This key contains one or multiple paths to files showing screenshots of the software. They are meant to give a quick idea on how the software looks like and how it works. The key value can be the relative path to the file starting from the root of the repository, or it can be an absolute URL pointing to the screenshot in raw version. In both cases, the file must reside inside the same repository where the publiccode.yml file is stored.

Screenshots can be of any shape and size; the suggested formats are:

- Desktop: 1280x800 @1x
- Tablet: 1024x768 @2x
- Mobile: 375x667 @2x | No |
| videos | array | This key contains one or multiple URLs of videos showing how the software works. Like screenshots, videos should be used to give a quick overview on how the software looks like and how it works. Videos must be hosted on a video sharing website that supports the oEmbed standard; popular options are YouTube and Vimeo.

Since videos are an integral part of the documentation, it is recommended to publish them with an open license. | No |
| awards | array | A list of awards won by the software. | No |
