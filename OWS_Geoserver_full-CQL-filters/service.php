<?php

/**
 * USE SUITABLE SERVER PROXYING TO RELAY ALL REQUESTS WITH CQL_FILTERS AND BBOX TO THIS SCRIPT,
 * (Apache: mod_rewrite in the virtual host, e.g. RewriteCond "%{REQUEST_URI}" "CQL_FILTER|BBOX" [NC])
 * AND ALL REQUESTS WITHOUT CQL_FILTERS AND BBOX DIRECTLY TO THE GEOSERVER INSTANCE
 * (e.g. mod_rewrite in the virtual host, e.g. RewriteCond "%{REQUEST_URI}" "!CQL_FILTER" [NC] combined
 * with RewriteCond "%{REQUEST_URI}" "!BBOX" [NC], I.E. PROCESS ONLY QUERIES WITH FILTERS IN PHP
 */

/**
 * This script filters and relays a request from the EPOS portal to Geoserver. The portal uses IETF RFC6570 URI templating,
 * which does not support the templating necessary for CQL (or OGC) filters, as optional encoding of variables in the
 * CQL-part of the URI template is not supported. Thus, all filters are encoded by the portal, even if empty. Geoserver
 * interprets also empty filters as values and thus, would not return the expected data. The script removes the empty
 * CQL filters, sends the query to Geoserver and forwards the reply to the portal.
 */

// Define Geoserver base URL and service path for requests
    $geoserverBaseURL = 'http://localhost:8080/geoserver';
    $geoserverServicePath = '/<your_namespace>/ows?';
    
/**
 * Vocabulary-attribute names. Any attribute in $vocabulary contains a value that is defined by a vocabulary. The helper-attribute
 * 'abcPath" exposes the full hierarchy of the vocabulary-value as a stop-separated list ("Path"), to provide for filtering entire
 * hierarchies, primarily to include records that contain children to specified parent values.
 */
    $vocabulary = [
        'vocabulary1_name', 
        'vocabulary2_name'
    ];

// Define @epos_style styling parameters according to the documentation of EPOS GeoJSON (to be injected before returning the response)
// (https://epos-eric.github.io/opensource-docs/documentation/system-reference/data-formats/geojson)
    $eposStyle = array(
        "surveyPoint" => array("label" => "Font Awesome Arrows to Point"),
        "marker"      => array(
            "fontawesome_class" => "fa-regular fa-arrows-to-dot fa-spin fa-spin-reverse",
            "pin"               => "false",
            "clustering"        => "true",
            "anchor"            => "C"));
    $eposStyle = json_encode($eposStyle);

// Array with the geometry columns (attributes) of the layer, that should be queried.
// Each database entry will only have one geometry filled, the geometry depending on the type of data set.
    $geometryColumns = ['geomPoint', 'geomLine', 'geomPolygon', 'geomCollection'];
    //$geometryColumns = ['geomLine']; // single geometry

// Pre-process the incoming URL and preprocess the query
    // 1. Get the raw, untouched URI structure first
    $requestUri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL);

    // 2. Parse the URL components *while it is still encoded*
    $parsedUrl = parse_url($requestUri);

    // 3. Extract the query component
    $queryString = isset($parsedUrl['query']) ? $parsedUrl['query'] : '';

    // 4. Now, decode the query string safely
    $decodedQueryString = rawurldecode($queryString);
    
    // 5. Preprocess the query
    $geoserverQuery = preprocessQuery($decodedQueryString);

// Query Geoserver with the preprocessed query
    $geoserverData = getGeoserverData($geoserverQuery);

// Insert the EPOS GeoJSON style information before returning the data
    $eposData = preg_replace('#"type":\s*"FeatureCollection"#', '"type": "FeatureCollection", "@epos_style": ' . $eposStyle, $geoserverData);

// Return the data to the client
    returnData($eposData);


/**
 * Processes the query string:
 *   Test for bbox-filter in the main KVP-string.
 *   If the bbox-filter is there, CQL-filters are not allowed. Convert the bbox-filter to a CQL-filter with all geometries
 *   Remove empty CQL filters
 *   URL-encode the CQL filters
 *   Returns the correct query string for Geoserver.
 *
 * @param string $query     The query part of the original URI.
 * @return string
 */
function preprocessQuery ($query) {
    global $geometryColumns;

    // separate the CQL filter string
    preg_match('#(.+CQL_FILTER=)(.+)#', $query, $cqlMatches);
    // a switch that triggers CQL-filter processing in case $cqlMatches is not empty, which is the case for all queries from the EPOS portal,
    // and alternatively allows WFS-queries without CQL-filters, with or without KVP-bounding box
    switch (true) {
        case !empty($cqlMatches):
            // remove empty CQL filters from the EPOS portal
            $CQL = preg_replace('#\w+=\'\?\'\sAND\s#', '', $cqlMatches[2]);
            // replace + characters in the attribute values with literal spaces (the + character encoding for spaces are part of the econding at the ICS-C)
            $CQL = preg_replace_callback("/'([^']+)'/", function($matches) {
                // $matches[1] contains the text strictly inside the single quotes
                return "'" . str_replace('+', ' ', $matches[1]) . "'";
            }, $CQL);
            // change request format from "json" (as required by the portal for visibility in map and table view) to
            // application/json, for correct processing by Geoserver
            $baseQuery = preg_replace('#outputFormat=json#', 'outputFormat=application/json', $cqlMatches[1]);
            // reformat comparative and range filters
            $CQL = convertComparativeAndRangeFilters($CQL);
            // check whether the query contains a CQL bounding box
            switch (true) {
                // if not, do noting
                case !preg_match("#bbox#i", $CQL):
                    break;
                // else, process the bounding box
                default:
                    // remove bbox from $CQL, to treat it separately before adding it again - else there is a risk of mixing up attributes in the filter string
                    $CQL = trim(preg_replace('#(?:AND\s*)?bbox\(geometry,-?\d+,-?\d+,-?\d+,-?\d+,*[\d\w:\']*\)#', '', $CQL), " AND");
                    // if only one geometry column is specified, a simple replacement of the bbox geometry-placeholder is sufficient,
                    // else, a more elaborate concatenation is required.
                    if (count($geometryColumns) == 1) {
                        $CQL = str_replace("geometry", $geometryColumns[0], $CQL);
                    } else {
                        // extract bounding box
                        preg_match('#bbox\(geometry,(.+)\)#', $cqlMatches[2], $bboxMatches);
                        // test if $CQL is not empty, if not empty add the AND for the following bbox statement
                        if (!empty($CQL)) {
                            $CQL .= " AND ";
                        }
                        // add first bbox with the first geometry and remove the latter from the array
                        $CQL .= "(bbox(" . array_shift($geometryColumns). "," . $bboxMatches[1] . ')';
                        // add bbox-filters for the remaining geometries
                        foreach ($geometryColumns as $geometryColumn) {
                            $CQL .= " OR bbox(" . $geometryColumn . "," . $bboxMatches[1] . ')';
                        }
                        // close paranthesis after the last bbox (end of filter)
                        $CQL .= ")";
                    }
            }
            // Convert attributes that are goverened by vocabularies for hierarchical search
            $CQL = convertVocabularyAttributes($CQL);
            // URL encode the remaining CQL filters
            $CQL = rawurlencode($CQL);
            // assemble query for Geoserver
            $geoserverQuery = $baseQuery . $CQL;
            break;
        default:
            // detect bbox in the main KVP query
            preg_match('#(.+)&bbox=([-+]?[0-9]*\.?[0-9]+),([-+]?[0-9]*\.?[0-9]+),([-+]?[0-9]*\.?[0-9]+),([-+]?[0-9]*\.?[0-9]+)#', $query, $bboxCoordinates);
            switch (true) {
                case !empty($bboxCoordinates):
                    // transform KVP bounding box to CQL bounding box
                    // add first geometry column with filter and remove it fromt he array
                    $CQL = "(bbox(" . array_shift($geometryColumns) . "," . $bboxCoordinates[2] . "," . $bboxCoordinates[3] . "," . $bboxCoordinates[4] . "," . $bboxCoordinates[5] . ")";
                    foreach ($geometryColumns as $geometryColumn) {
                        $CQL .= " OR bbox(" . $geometryColumn . "," . $bboxCoordinates[2] . "," . $bboxCoordinates[3] . "," . $bboxCoordinates[4] . "," . $bboxCoordinates[5] . ")";
                    }
                    // close paranthesis after the last bbox (end of filter)
                    $CQL .= ")";
                    // Convert attributes that are goverened by vocabularies for hierarchical search
                    $CQL = convertVocabularyAttributes($CQL);
                    // URL encode the CQL-bounding box filter
                    $CQL = rawurlencode($CQL);
                    // assemble query for Geoserver
                    $geoserverQuery = $bboxCoordinates[1] . "&CQL_FILTER=" . $CQL;
                    break;
                default:
                    // without any bounding box and CQL-filter parameters, forward the query as it is to Geoserver
                    $geoserverQuery = $query;
                    break;
            }
            break;
    }
    return $geoserverQuery;
}


/**
 * Subfunction to process comparative and range filters in the CQL-string:
 *   Detects </>/- in the filter parameter, like
 *        property='<XXXX'
 *        property='>XXXX'
 *        property='XXXX--YYYY' or property='XXXX to YYYY'
 *   Replaces the input with a correct CQL-filter, like
 *        property<'XXXX'
 *        property>'XXXX'
 *        property BETWEEN 'XXXX' AND 'YYYY'
 *
 * @param string $CQL     The CQL_FILTER string of the original query.
 * @return string
 */
function convertComparativeAndRangeFilters($CQL) {
    // Define the patterns to search for
    $patterns = [
        // Rule 1 & 2: Matches: prop='>val' or prop='<val'
        // Breakdown:
        // ([a-zA-Z0-9]+) : Match 1 - The property name (letters & numbers)
        // ='             : Literal characters
        // ([<>])         : Match 2 - The operator (< or >)
        // ([^']*)        : Match 3 - The value (anything except a quote)
        "/([a-zA-Z0-9]+)='([<>])([^']*)'/",

        // Rule 3: Matches: prop='val1--val2'
        // Breakdown:
        // ([a-zA-Z0-9]+) : Match 1 - The property name
        // ='             : Literal characters
        // ([^'-+]+)       : Match 2 - Start value (anything except quote or hyphen)
        // \s*(?:--|to)\s*: The double-hyphen or 'to' separator, surrounded by (space OR +) or not (+ because of URL encoding)
        // ([^'+]+)        : Match 3 - End value (anything except quote)
        "/([a-zA-Z0-9]+)='([^' -+]+)(?:\s*|\+*)(?:--|to)(?:\s*|\+*)([^'+]+)'/"
    ];

    // Define the corresponding replacements
    $replacements = [
        // Replacement for Rule 1 & 2:
        // Removes the = and moves the operator outside the quotes
        '$1$2\'$3\'',

        // Replacement for Rule 3:
        // Converts hyphen syntax to SQL BETWEEN syntax
        '$1 BETWEEN \'$2\' AND \'$3\''
    ];


    // Perform all replacements in one go
    return preg_replace($patterns, $replacements, $CQL);
}

/**
 * Subfunction for attributes that contain vocabulary values. Attribute 'abcPath' contains the
 * stop-separated vocabluary hierarchy ("Path") of the 'abc'-value. This function replaces
 * attribute "abc" with "abcPath" and the equal sign with a like statement to search
 * the hierarchy. In this way, also the values that are hierarchically below the present
 * queried value are included.
 * This function requires that attributes with the hierarchical information are exposed to Geoserver!
 *
 * @param string $CQL     The CQL_FILTER string of the original query.
 * @return string
 */
function convertVocabularyAttributes($CQL) {
    global $vocabulary;
    
    // Escape names and join them with '|' to create a regex alternation group
    // Result: (domainPath|formatPath|investigationMethodPath|...)
    $regexAttributes = implode('|', array_map('preg_quote', $vocabulary));

    // Build the master regex pattern
    // It looks for: word_boundary + attribute_name + word_boundary + optional_spaces + '=' + optional_spaces + 'value_inside_single_quotes'
    $pattern = '/\b(' . $regexAttributes . ')\b\s*=\s*\'([^\']+)\'/';

    // Perform the dynamic transformation
    $CQL = preg_replace_callback($pattern, function($matches) {
        // $matches[1] contains the attribute name (e.g., 'domainPath')
        // $matches[2] contains the literal value searched (e.g., 'Solid Earth' or 'Seismic-Data')
        $attribute = $matches[1] . 'Path';
        $originalValue = $matches[2];

        // CRITICAL: Sanitize the incoming value exactly how the PostGIS materialized view did.
        // This replaces spaces, hyphens, and special characters with underscores so it matches the ltree path nodes.
        $sanitizedValue = preg_replace('/[^a-zA-Z0-9_]/', '_', $originalValue);

        // Reconstruct into the hierarchical expansion clause
        // Example: (domainPath LIKE 'Solid_Earth.%' OR domainPath = 'Solid_Earth')
        return "({$attribute} LIKE '%,{$sanitizedValue}.%' OR {$attribute} LIKE '%,{$sanitizedValue},')";
    }, $CQL);
    
    return $CQL;    
}


/**
 * Queries Geoserver and returns the result.
 *
 * @param string $geoserverBaseURL     The base URL to the Geoserver instance (usually the URL to the admin page).
 * @return json
 */
function getGeoserverData ($geoserverQuery) {
    global $geoserverBaseURL;
    global $geoserverServicePath;

    // concatenate the query URL
    $geoserverQueryURL = $geoserverBaseURL . $geoserverServicePath . $geoserverQuery;
    // execute the query and test whether it was successful
    $response = file_get_contents($geoserverQueryURL);
    // test whether the response contains data or an error
    geoserverResponseCheck($response);

    return $response;
}


/**
 * Sets correct headers and returns the data to the client.
 *
 * @param string $data    The data to be sent to the client.
 */
function returnData($data) {
    header('Content-type: application/JSON');
    header('Pragma: no-cache'); // Prevents caching
    header('Expires: 0'); // Prevents caching

    echo $data;
}


/**
 * Test if Geoserver produced an error. In case of error, the response contains an error message and not data,
 * which causes the function to throw an exception that includes the Geoserver error message.
 *
 * @param string $response    The response from Geoserver.
 * @throws Exception          If the response is not valid (incorrect request).
 */
function geoserverResponseCheck ($response) {
    try {
        // $response is of type string, test if it contains an array of data encoded as string; if not, throw an exception
        if (!preg_match('#^{"#', $response)) {
            throw new Exception("EPOS CSS Service: The query is not a valid request. Please check your query carefully against the documentation!", 422);
        }
    } catch (Exception $ex) {
        header('Content-Type: text/html');
        echo('EXCEPTION - Code: ' . $ex->getCode() . "<br>");
        echo($ex->getMessage());
    }
}
?>
