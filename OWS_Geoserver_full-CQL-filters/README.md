# OWS requests (here WFS) with fully functional CQL-filters
OGC XML-based filters, as described in the WFS standard, are complicated and cumbersome to use in URL-based queries. [Geoserver](https://www.geoserver.org)'s WFS implementation provides also OGC [CQL/ECQL filters](https://docs.geoserver.org/latest/en/user/tutorials/cql/cql_tutorial.html) (not part of the WFS standard) as an easier approach to compose filters. \
The EPOS data portal ([EPOS Platform Open Source](https://epos-eric.github.io/opensource-docs/)) uses [IETF RFC6570 URI templating](https://datatracker.ietf.org/doc/html/rfc6570), which does not support the templating necessary for CQL-filters (and OGC-filters), because optional encoding of variables in the CQL-part of the URI template is not supported. Thus, all defined CQL filters are always encoded, even if empty. Geoserver interprets also empty filters as values and thus, would not return the expected data. The script removes the empty CQL filters, sends the query to Geoserver and forwards the reply to the portal.

### Features:
* Removal of empty filters
* Support of multiple geometry columns (e.g. for data types where the geometery for each data sets is not fixed, e.g. point OR line)
* Support of comparative (larger than, smaller than) and range filters

=> Other functionality of CQL-filters, as described in the [CQL reference](https://docs.geoserver.org/latest/en/user/filter/ecql_reference.html#filter-ecql-reference), is not supported but can be implemented in a similar way.

### Requirements:
* A Geoserver WFS service that is configured to produce the desired service payload. \
=> Use EPOS-extended GeoJSON for best compatibility with EPOS Platform Open Source (Recommendation: Geoserver's [Features-Templating extension](https://docs.geoserver.org/main/en/user/community/features-templating/index.html))
* Correct EPOS DCAT-AP metadata for the service (see [documentation](https://epos-eu.github.io/EPOS-DCAT-AP/v3/), an example for the definition of the URL with filters is provided in [example.ttl](https://github.com/daoane/epos-services/edit/main/OWS_Geoserver_full-CQL-filters/example.ttl))

The service.php script expects a request from the data portal according to the example provided in [example.ttl](https://github.com/daoane/epos-services/edit/main/OWS_Geoserver_full-CQL-filters/example.ttl)). It deconstructs an incoming request, reformats its parts to comply with CQL-filter specifications, constructs the WFS query-URL, sends the request to Geoserver and forwards the reply to the client (the data portal).

Initial service requests by the EPOS Platform Open Source do not include filters. Users often do not use filters while exploring the data. Thus, a large part of the requests from the EPOS data portal do not include filters. To avoid unnecessary load on the server, it is recommended to configure the web server to relay queries without filters directly to Geoserver, bypassing the service.php script (e.g. with Apache mod_rewrite in the virtual host, e.g. RewriteCond "%{REQUEST_URI}" "!CQL_FILTER=").
