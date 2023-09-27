# Contact

No description available.

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/Contact.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| name | string | This key contains the full name of one of the technical contacts. It must be a real person; do NOT populate this key with generic contact information, company departments, associations, etc. | Yes |
| email | string | This key contains the e-mail address of the technical contact. It must be an email address of where the technical contact can be directly reached; do NOT populate this key with mailing-lists or generic contact points like “info@acme.inc”. The e-mail address must not be obfuscated. To improve resistance against e-mail collection, use \x64 to replace @, as allowed by the YAML specification. | No |
| phone | string | phone number (with international prefix). This has to be a string. | No |
| affiliation | string | This key contains an explicit affiliation information for the technical contact. In case of multiple maintainers, this can be used to create a relation between each technical contact and each maintainer entity. It can contain for instance a company name, an association name, etc. | No |
