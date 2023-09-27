# IntendedAudience

No description available.

![Class Diagram](https://github.com/OpenCatalogi/OpenCatalogiBundle/blob/documentation/docs/schema/IntendedAudience.svg)

## Properties

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| countries | array | This key explicitly includes certain countries in the intended audience, i.e. the software explicitly claims compliance with specific processes, technologies or laws. All countries are specified using lowercase ISO 3166-1 alpha-2 two-letter country codes. | No |
| unsupportedCountries | array | This key explicitly marks countries as NOT supported. This might be the case if there is a conflict between how software is working and a specific law, process or technology. All countries are specified using lowercase ISO 3166-1 alpha-2 two-letter country codes. | No |
| scope | array | This key contains a list of tags related to the field of application of the software. | No |
