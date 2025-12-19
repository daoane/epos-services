# Complex discovery and data download service
This example was developed for the MT-service (EMTDAMO) of TCS Geomagnetic Observations. The problem solved by this service is to provide multiple data downloads for MT stations within one service, i.e. not duplicating the discovery procedure for each data type.

### Features

* Discovery of MT stations with [OGC WFS](https://www.ogc.org/standards/wfs/).
* Download of transfer functions (TF) for the stations in the WFS discovery as one JSON data file or as a ZIP-archive of JSON files.
* Download of transfer functions (TF) for single or multiple stations by station name(s), as one JSON data file or as a ZIP-archive of JSON files.

### Requirements
* A Geoserver WFS service that is configured to produce the desired service payload.
* An internal Geoserver WFS service that supplies the database PKs for queried stations.

### Files and their function
#### [mtService.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/mtService.php)
_"mtService.php - The allocator script that evaluates the URL and, accordingly, calls the required functions and returns the data to the client."_

mtService.php is the service endpoint. It deconstructs the incoming URL, determines the type of the requested response, calls the respective functions and returns the service payload to the client. In the download-window of the [EPOS data portal](https://www.ics-c.epos-eu.org/) ([EPOS Platform Open Source](https://github.com/epos-eu/epos-open-source)), available downloads are advertised as pairs of "File Name" and "File Format" ('outputFormat'), values that are set in the [EPOS DCAT-AP](https://epos-eu.github.io/EPOS-DCAT-AP/v3/) metadata. The variable 'outputFormat' is part of the WFS query string and thus, it can be used for determining the requested data type (function getService).
1. 'outputFormat=application/geo+json' or 'outputFormat=geo+json' triggers MT station discovery (calls function discovery in file [discovery.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/discovery.php)).
2. 'outputFormat=application/json', 'outputFormat=json' and 'outputFormat=zip' triggers download of TF data for the stations in the WFS query as a single JSON file or as a ZIP archive of JSON files (calls function tfDataFromDiscover in file [tfDataFromDiscovery.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/tfDataFromDiscovery.php)).
3. In addition, TF data download per station ('siteName') is provided, also as one JSON file or as a ZIP-archive of JSON files (calls function tfDataBySite in file [tfDataBySite.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/tfDataBySite.php)). Rhe discovery data of each station contain data links based on 'siteName'.

Please refer to the inline documenation of the php-code for details.

<sup>[While transfer functions are stored in the database, the full data for each station have a substantial size and therefore, are stored as individual files on a file server. Files can be linked directly from the discovery data]</sup>

#### [discovery.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/discovery.php)
_discovery.php - Implements the discovery service (as used by the EPOS data portal)._

Redirects the incoming WFS-query to Geoserver and returns the response's payload to mtService.php, which in turn sends it to the client.

<sup>["Fix for Geoserver JSON array": It is not possible to assemble a JSON array from related tables in Geoserver, as the code that handles related tables was written for XML (AppSchema extension). A workaround on the database and Geoserver side provides comma separated values. This part of the fix transforms comma separated strings to JSON arrays.]</sup>

#### [tfDataFromDiscovery.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/tfDataFromDiscovery.php)
_tfDataFromDiscovery.php - Download of transfer functions based on the discovery WFS request, either as one JSON file or as a zipped file of individual JSON files._

Provides
* function siteIdfromGeoserver, which requests the database PKs for the stations in a WFS discovery
* function tfDownloadJSON, uses function siteIdfromGeoserver and executes a database query with the returned PKs and requested data types. The TF data (station..data: 1..n) are aggregated to a single JSON file already during the query.
* function tfDownloadZIP, uses function siteIdfromGeoserver and executes a database query for each ID and each data type. The returned JSON is then written as individual files to a ZIP-archive.

#### [tfDataBySite.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/tfDataBySite.php)
_tfDataBySites.php - Download of transfer functions for individual or multiple sites, either as one JSON file or as a zipped file of individual JSON files._

Provides:
* function tfSiteJSON, which takes one or multiple site names and requested data types, and executes a database query. The TF data (station..data: 1..n) are aggregated to a single JSON file already in the query.
* function tfSiteZIP, which takes one or multiple site names and requested data types, and executes a database query for each site and each data type. The returned JSON is then written as individual files to a ZIP-archive.

#### [auxiliaries.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/auxiliaries.php)
_auxiliaries.php - Repeatedly used helper-functions needed by the other scripts._

Check of data and service response, database queries

#### [eposgoPgConfig.php](https://github.com/daoane/epos-services/blob/main/combined-WFS-discovery-multiple-data-access/eposgoPgConfig.php)
eposgoPgConfig.php - Contains the database credentials that are sourced by the scripts. Requires access by the web server. Must not be accessible from the web.

### Example URLs
#### Discovery
`https://template.abc/mtService.php?service=WFS&version=2.0.0&request=GetFeature&typeNames=eposgo:MTSiteView&srsName=CRS:84&outputFormat=application/geo+json&cql_filter=bbox(geometry,12,50,14,54)`

#### TF download after discovery ZIP and JSON
`https://template.abc/mtService.php?service=WFS&version=2.0.0&request=GetFeature&typeNames=eposgo:MTSiteView&srsName=CRS:84&outputFormat=application/geo+json&cql_filter=bbox(geometry,12,50,14,54)`

`https://template.abc/mtService.php?service=WFS&version=2.0.0&request=GetFeature&typeNames=eposgo:MTSiteView&srsName=CRS:84&outputFormat=application/json&cql_filter=bbox(geometry,12,50,14,54)&dataType=IMP,TIP`

#### Site downlioad ZIP and JSON
`https://template.abc/mtService.php?siteName=MIDCR_m15,MIDCR_m16,MIDCR_m18&outputFormat=application/zip&dataType=TIP,HTF`

`https://template.abc/mtService.php?siteName=MIDCR_m15,MIDCR_m16,MIDCR_m18&outputFormat=application/json&dataType=TIP,HTF`
