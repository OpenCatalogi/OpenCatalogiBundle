# Component

Based on the [top level component](https://yml.publiccode.tools/schema.core.html#top-level-keys-and-sections) of public code. represent a pease of software that may iether be  standalone or part of a larger application.

**The PublicCode standard has been extenden for this API in te following way's**
- To the `type` property the following enums where added
- - Api
- - Schema
- - Data
- The `inputType` property was used (its depracticed in PublicCode)
- The `outputType` property was uses (its depracticed in PublicCode)
- The `nl` country exenstion was added in line with the [PublicCode country extensions](https://yml.publiccode.tools/country.html) guidelines
- applicationId was added for the codebase repository (like a github repository id).

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Component.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| applicationId | string | N/A | No |
| name | string | This key contains the name of the software. It contains the (short) public name of the product, which can be localised in the specific localisation section. It should be the name most people usually refer to the software. In case the software has both an internal “code” name and a commercial name, use the commercial name. | Yes |
| applicationSuite | Any | N/A | No |
| url | Any | N/A | No |
| landingURL | string | If the url parameter does not serve a human readable or browsable page, but only serves source code to a source control client, with this key you have an option to specify a landing page. This page, ideally, is where your users will land when they will click a button labeled something like “Go to the application source code”. In case the product provides an automated graphical installer, this URL can point to a page which contains a reference to the source code but also offers the download of such an installer. | No |
| isBasedOn | string | In case this software is a variant or a fork of another software, which might or might not contain a publiccode.yml file, this key will contain the url of the original project(s).

The existence of this key identifies the fork as a software variant, descending from the specified repositories. | No |
| softwareVersion | string | This key contains the latest stable version number of the software. The version number is a string that is not meant to be interpreted and parsed but just displayed; parsers should not assume semantic versioning or any other specific version format.

The key can be omitted if the software is currently in initial development and has never been released yet. | No |
| releaseDate | string | This key contains the date at which the latest version was released. This date is mandatory if the software has been released at least once and thus the version number is present. | No |
| logo | string | This key contains the path to the logo of the software. Logos should be in vector format; raster formats are only allowed as a fallback. In this case, they should be transparent PNGs, minimum 1000px of width. The key value can be the relative path to the file starting from the root of the repository, or it can be an absolute URL pointing to the logo in raw version. In both cases, the file must reside inside the same repository where the publiccode.yml file is stored. | No |
| platforms | array | This key specifies which platform the software runs on. It is meant to describe the platforms that users will use to access and operate the software, rather than the platform the software itself runs on.

Use the predefined values if possible. If the software runs on a platform for which a predefined value is not available, a different value can be used. | No |
| categories | array | A list of words that can be used to describe the software and can help building catalogs of open software.

The controlled vocabulary List of software categories contains the list of allowed values. | No |
| usedBy | array | A list of the names of prominent public administrations (that will serve as “testimonials”) that are currently known to the software maintainer to be using this software.

Parsers are encouraged to enhance this list also with other information that can obtain independently; for instance, a fork of a software, owned by an administration, could be used as a signal of usage of the software. | No |
| roadmap | string | A link to a public roadmap of the software. | No |
| developmentStatus | string | The keys are:


-  concept - The software is just a “concept”. No actual code may have been produced, and the repository could simply be a placeholder.
- development - Some effort has gone into the development of the software, but the code is not ready for the end user, even in a preliminary version (beta or alpha) to be tested by end users.
- beta - The software is in the testing phase (alpha or beta). At this stage, the software might or might not have had a preliminary public release.
- stable - The software has seen a first public release and is ready to be used in a production environment.
- obsolete - The software is no longer maintained or kept up to date. All of the source code is archived and kept for historical reasons. | No |
| softwareType | string | The keys are:

- standalone/mobile - The software is a standalone, self-contained The software is a native mobile app.
- standalone/iot - The software is suitable for an IoT context.
- standalone/desktop - The software is typically installed and run in a a desktop operating system environment.
- standalone/web - The software represents a web application usable by means of a browser.
- standalone/backend - The software is a backend application.
- standalone/other - The software has a different nature from the once listed above.
- softwareAddon - The software is an addon, such as a plugin or a theme, for a more complex software (e.g. a CMS or an office suite).
-  library - The software contains a library or an SDK to make it easier to third party developers to create new products.
- configurationFiles - The software does not contain executable script but a set of configuration files. They may document how to obtain a certain deployment. They could be in the form of plain configuration files, bash scripts, ansible playbooks, Dockerfiles, or other instruction sets. | No |
| intendedAudience | Any | N/A | No |
| description | Any | N/A | No |
| legal | Any | N/A | No |
| maintenance | Any | N/A | No |
| localisation | Any | N/A | No |
| dependsOn | Any | N/A | No |
| nl | Any | N/A | No |
| inputTypes | array | A list of Media Types (MIME Types) as mandated in RFC 6838 which the application can handle as input.

In case the software does not support any input, you can skip this field or use application/x.empty | No |
| outputTypes | array | A list of Media Types (MIME Types) as mandated in RFC 6838 which the application can handle as output.

In case the software does not support any output, you can skip this field or use application/x.empty | No |
| rating | Any | N/A | No |
