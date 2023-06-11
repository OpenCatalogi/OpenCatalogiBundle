# Public Organisation YAML Specification
The publicorganisation.yaml file is a proposed standard for identifying and providing information about public organisations on GitHub. This file should be placed in the .github directory of a GitHub organisation's repository. It serves as a declaration of the organisation's identity, including its name, website, contact details, and the software it uses and supports.

By implementing this file, public organisations can more effectively communicate their software portfolio and contribute to a more transparent, open-source ecosystem. Each organisation's publicorganisation.yaml file provides crucial information that can be used by developers, citizens, and other stakeholders to understand the organisation's activities and commitments in the open-source domain.

One key aspect of this open-source commitment is the support an organisation provides for certain software. This includes both software that the organisation owns and software owned by other organisations. The support provided can take various forms, such as maintenance, hosting, software as a service (SaaS), or other services. These details are encapsulated in a maintenance object under the softwareSupported field.

The maintenance object represents the type and level of support the organisation provides for a given software. It includes details like the type of maintenance and contact information for maintenance enquiries.

Here's an example publicorganisation.yaml file:

```yaml
publicorganisationYmlVersion: "0.2"

# The official name of the public organisation
name: 'Public Organisation Name'

# The official website of the public organisation
website: 'https://www.publicorganisationwebsite.com'

# The contact details of the public organisation
contact:
  email: 'contact@publicorganisation.com' # Public contact email
  phone: '+1234567890' # Public contact phone number

# List of software the public organisation owns. keep in mind that owns dosn't automaticly means maintained
# Each item refers to the repository URL of the software
softwareOwned:
  - 'https://github.com/organisation/software1'
  - 'https://github.com/organisation/software2'
  
# List of software the public organisation uses
# Each item refers to the repository URL of the software
softwareUsed:
  - 'https://github.com/organisation/software1'
  - 'https://github.com/organisation/software2'

# List of software the public organisation supports
# Each item is a maintenance object representing the support provided for a software
softwareSupported:
  - software: 'https://github.com/organisation/software2'
    type: 'maintenance' # The type of support provided
    contact:
      email: 'contact@publicorganisation.com' # Public contact email
      phone: '+1234567890' # Public contact phone number
  - software: 'https://github.com/organisation/software2'
    type: 'saas' # The type of support provided
    contact:
      email: 'contact@publicorganisation.com' # Public contact email
      phone: '+1234567890' # Public contact phone number
```

Here is a table presenting all the properties in the publicorganisation.yaml file:

|Key| 	Type                    | 	Description                                                                                             |
|---|--------------------------|----------------------------------------------------------------------------------------------------------|
|name| 	String                  | 	The official name of the public organisation                                                            |
|website| 	String (URL)            | 	The official website of the public organisation                                                         |
|contact| 	Object                  | 	The contact details of the public organisation                                                          |
|contact.email| 	String                  | 	The public contact email of the public organisation                                                     |
|contact.phone| 	String                  | 	The public contact phone number of the public organisation                                              |
|softwareUsed| 	Array of Strings (URLs) | 	List of software the public organisation uses, represented by their repository URLs                     |
|softwareSupported|	Array of Objects (maintenance)|	List of software the public organisation supports, represented by their maintenance objects
|softwareSupported[].software|	String|	The software that the organisation supports
|softwareSupported[].type|	String|	The type of support provided for the software, one of "Hosting","SAAS","Support","Maintenance","Training","Consultancy","Purchase"
|softwareSupported[].contact|	Object|	The contact details of the support |
|softwareSupported[].contact.email| 	String                  | 	The public contact email of the public organisation                                                     |
|softwareSupported[].contact.phone| 	String                  | 	The public contact phone number of the public organisation                                              |
