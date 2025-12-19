# Examples of PHP service endpoints for the EPOS ERIC data portal (EPOS Platform Open Source)
The repository "epos-services" contains a collection of php-scripts that act as service endpoint for data services of the [EPOS Platform Open Source](https://epos-eric.github.io/opensource-docs/), derived from working service implementations for the [EPOS ERIC data portal](https://www.ics-c.epos-eu.org/) (Integrated Core Services, ICS-C).

## Purpose
These scripts are published as examples that hopefully help others on their way to fully functional EPOS data services. They were developed to bridge the gap between the EPOS Platform Open Source/EPOS ICS-C's functionality to compose query-URLs and the URLs that actually are required by the data standards and their implementations. Some address also the need of thematic communities (Thematic Core Services, TCS) to do things differently from how it was intended by the ICS-C (e.g. combining discovery and full data provision in a single service).

## Examples
### Folder [OWS_Geoserver_full-CQL-filters](https://github.com/daoane/epos-services/blob/main/OWS_Geoserver_full-CQL-filters):
Provides an example implementation for an OGC WFS with with fully functional CQL-filters. <br />

### Folder [combined-WFS-discovery-multiple-data-access](https://github.com/daoane/epos-services/tree/main/combined-WFS-discovery-multiple-data-access)
A complex service that provides data discovery by WFS and data access to database content for a specific discovery request (JSON, ZIP). <br />
