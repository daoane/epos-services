# OWS requests (here WFS) with fully functional CQL-filters
OGC XML-based filters, as described in the WFS standard, are complicated and cumbersome to use in URL-based queries. [Geoserver](https://www.geoserver.org)'s WFS implementation provides also OGC [CQL/ECQL filters](https://docs.geoserver.org/latest/en/user/tutorials/cql/cql_tutorial.html) (not part of the WFS standard) as an easier approach to compose filters. \
The EPOS data portal ([EPOS Platform Open Source](https://epos-eric.github.io/opensource-docs/)) uses [IETF RFC6570 URI templating](https://datatracker.ietf.org/doc/html/rfc6570), which does not support the templating necessary for CQL-filters (and OGC-filters), because optional encoding of variables in the CQL-part of the URI template is not supported. Thus, all defined CQL filters are always encoded, even if empty. Geoserver interprets also empty filters as values and thus, would not return the expected data. The script removes the empty CQL filters, sends the query to Geoserver and forwards the reply to the portal.

### Features:
* Removal of empty filters
* Conversion of whitespace in attribute values from ICS-C encoding as + character to literal whitespace, as required in the filter URIs (assuming that + characters are not part of attribute values!) - v1.2
* Support of multiple geometry columns (e.g. for data types where the geometery for each data sets is not fixed, e.g. the geometry could be a point OR a line feature)
* Support of comparative (larger than, smaller than) and range filters
* Hierarchical filtering with controlled vocabularies ([requires adjustments to the database and Geoserver layer](#hierarchical-filtering-with-controlled-vocabularies)) - v1.2

=> Other functionality of CQL-filters, as described in the [CQL reference](https://docs.geoserver.org/latest/en/user/filter/ecql_reference.html#filter-ecql-reference), is not supported but can be implemented in a similar way.

=> The script also transforms WFS KVP-bounding boxes into CQL-filter bounding boxes while taking care of multiple geometries.

### Requirements:
* A Geoserver WFS service that is configured to produce the desired service payload. \
=> Use EPOS-extended GeoJSON for best compatibility with EPOS Platform Open Source (Recommendation: Geoserver's [Features-Templating extension](https://docs.geoserver.org/main/en/user/community/features-templating/index.html))
* Correct EPOS DCAT-AP metadata for the service (see [documentation](https://epos-eu.github.io/EPOS-DCAT-AP/v3/); an example for the definition of the URL with filters is provided in [example.ttl](https://github.com/daoane/epos-services/edit/main/OWS_Geoserver_full-CQL-filters/example.ttl))
* In service.php, adapt the variables that contain the URLs to the Geoserver service/layer.

The service.php script expects a request from the data portal according to the example provided in [example.ttl](https://github.com/daoane/epos-services/edit/main/OWS_Geoserver_full-CQL-filters/example.ttl)). It deconstructs an incoming request, reformats its parts to comply with CQL-filter specifications, constructs the WFS query-URL, sends the request to Geoserver and forwards the payload of the reply to the client (the data portal).

Initial service requests on the EPOS Platform (when activating a service) do not include filters. Users often do not use filters while exploring the data. Thus, a large part of the requests from the EPOS data portal do not include filters. To avoid unnecessary load on the server, it is recommended to configure the web server to relay queries without CQL-filters or KVP-bounding box directly to Geoserver, bypassing the service.php script (e.g. with Apache mod_rewrite in the virtual host, e.g. e.g. RewriteCond "%{REQUEST_URI}" "!CQL_FILTER" [NC] combined with RewriteCond "%{REQUEST_URI}" "!BBOX" [NC]).

### Hierarchical filtering with controlled vocabularies
(Single hierarchies only! Poly-hierarchies are not supported.) \
(This feature was implemented with the help of the Mistral LLM.)

The ICS-C provide filtering according to a plain list of terms only and are not aware of hierarchies within controlled vocabularies like
* term1
  * term1.1
  * term1.2
  * term1.3
* term2
 * term2.1
   * term2.1.1
   * term2.2.2 
 * term2.2

Thus, providing hierarchical vocabularies in ICS-C service metadata removes the hierarchical relationships. Filtering for "term2" will only return exact matches and not, as expected, matches with term2, term2.1, term2.1.1, term2.1.2 and term2.2. Similarly, filtering for term2.1 should return features that match term2.1, term2.1.1 and term2.1.2.

To amend this problem, the hierarchical filter has to be implemented at the service provider side. The approach presented here has three parts: adjustments in the 1) [database](#database), 2) [update of the Geoserver layer](#update-of-the-geoserver-layer) and 3) [PHP code in the service.php script](#php-code-in-the-service.php-script).

#### Database
The vocabularies need to be present in the database with at least two columns: the term, and the related higher-level term ("broader"). For example:
```sql
CREATE TABLE schema_name.some_vocabulary (
	term varchar NOT NULL,
	definition varchar NOT NULL,
	uri varchar NOT NULL,
	broader varchar NULL
	CONSTRAINT some_vocabulary_pkey PRIMARY KEY (term),
	CONSTRAINT some_vocabulary_broader_fkey FOREIGN KEY (broader) REFERENCES schema_name.some_vocabulary(term) ON DELETE CASCADE ON UPDATE CASCADE
);
```
Based on the vocabulary, a view is created to provide the column for the hierarchical filtering. The example below is a materialized view that speeds up query execution time. However, it has to be refreshed after each update to the vocabulary (which usually infrequent).
```sql
CREATE MATERIALIZED VIEW schema_name.mv_some_vocabulary
AS WITH RECURSIVE vocabulary_tree AS (
         SELECT some_vocabulary.term,
            some_vocabulary.uri,
            some_vocabulary.definition,
            some_vocabulary.broader,
            regexp_replace(some_vocabulary.term::text, '[^a-zA-Z0-9_]'::text, '_'::text, 'g'::text) AS path_string
           FROM schema_name.some_vocabulary
          WHERE some_vocabulary.broader IS NULL
        UNION ALL
         SELECT child.term,
            child.uri,
            child.definition,
            child.broader,
            (parent.path_string || '.'::text) || regexp_replace(child.term::text, '[^a-zA-Z0-9_]'::text, '_'::text, 'g'::text) AS text
           FROM schema_name.some_vocabulary child
             JOIN vocabulary_tree parent ON child.broader::text = parent.term::text
        )
 SELECT term,
    uri,
    definition,
    broader,
    path_string::ltree AS lineage_path
   FROM vocabulary_tree
WITH DATA;

-- View indexes:
CREATE INDEX idx_gist_some_vocabulary ON schema_name.mv_some_vocabulary USING gist (lineage_path);
CREATE UNIQUE INDEX idx_term_some_vocabulary ON schema_name.mv_some_vocabulary USING btree (term);
CREATE INDEX idx_txtpat_some_vocabulary ON schema_name.mv_some_vocabulary USING btree (((lineage_path)::text) text_pattern_ops);
```
The column with the information for the hierarchical filtering has to be present in the table that is the basis for the data service. To achieve this, a view is created that joins the materialised views of all hierarchical vocabularies with the main data table.
```sql
CREATE OR REPLACE VIEW schema_name.table_name_vocab_lineage
AS SELECT id,
    attribute1,
    attribute2,
    ...,
    attributeX,
    ( SELECT (','::text || string_agg(m.lineage_path::text, ','::text)) || ','::text
           FROM unnest(string_to_array(s.domain::text, ','::text)) t(term)
             JOIN schema_name.mv_some_vocabulary m ON TRIM(BOTH FROM t.term) = m.term::text) AS some_vocabulary_path
# if needed, add additional vocabularies here
    ,
    ( SELECT (','::text || string_agg(m.lineage_path::text, ','::text)) || ','::text
           FROM unnest(string_to_array(s.domain::text, ','::text)) t(term)
             JOIN schema_name.mv_some_vocabulary2 m ON TRIM(BOTH FROM t.term) = m.term::text) AS some_vocabulary_path2
   FROM epos_css.survey_view s;
```
A materialized view with appropriate indexing would speed up the query time for larger database but requires a refresh after each update of the main data table!

#### Update of the Geoserver layer
To make the changes visible to Geoserver, an update to the layer that exposes the data as a service is required.
1) In the Geoserver data folder, open the file data/workspaces/<your_workspace>/<store_name>/<layer_name>/featuretype.xml
2) Change the content <nativeName> from table_name to table_name_vocab_lineage (i.e. from the name of the data table to the name of the new view)
3) Restart Geoserver
4) Open the "Data" tab of the layer in the adminstration interface and scroll down to the list of attributes under the heading "Feature Type Details". Add the new hierarchical "path" attributes ("some_vocabulary_path") of the view to the layer and save it.

IMPORTANT: The new attributes are now part of the layer and can be used for filtering. However, they may also unintentionally be part of the payload of the layer:
1) If "feature templating" is used for formatting the payload to (EPOS-extended) GeoJSON, the new attributes are available for filtering but will not appear in the payload (as they are not mapped in the template). Thus, the problem is solved "automatically".
2) If you do not use feature templating, you can add a function to the service.php script that removes the unwanted attributes before returning the payload to the ICS-C.
3) Services based on App-Schema will require a more intricate approach. The mapping file has to use the view instead of the data table, and the "some_vocabulary_path" attributes are required to be part of the mapping file to make filtering work. Then, these attributes have to be removed again by the service.php script.

#### PHP code in the service.php script
The update consists of several clearly commented parts:
1) The variable $vocabulary that contains a list of all vocabulary names.
2) Function convertVocabularyAttributes, which contains the filtering logics.
3) Two calls of the function convertVocabularyAttributes in the preprocessQuery function.
4) If feature templating is not used, add your own code for removing the undesired "some_vocabulary_path" attributes from the payload. 
