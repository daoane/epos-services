<?php

/**
 * USE SUITABLE SERVER PROXYING TO RELAY ALL REQUESTS WITHOUT ATTRIBUTE FILTERS
 * DIRECTLY TO THE GEOSERVER INSTANCE AND PROCESS ONLY QUERIES WITH FILTERS IN PHP
 * (Apache: mod_rewrite in the virtual host, e.g. RewriteCond "%{REQUEST_URI}" "!CQL_FILTER=")
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
    $geoserverServicePath = '/some/path/ows?'; // preview the layer in geoserver and copy the part of the URL before the ?
    
// define @epos-style styling parameters according to the documentation of EPOS GeoJSON (to be injected before returning the response)
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
    $geometryColumns = ['geomPoint', 'geomLine', 'geomPolygon'];
    //$geometryColumns = ['geomLine']; // single geometry
    
// Pre-process the incoming URL and preprocess the query
    $uri = parse_url(rawurldecode(filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL))); //assign query string to variable
    $geoserverQuery = preprocessQuery($uri["query"]);
    
// Query Geoserver with the preprocessed query
    $geoserverData = getGeoserverData($geoserverQuery);
    
// Insert the EPOS GeoJSON style information before returning the data
    $eposData = preg_replace('#"type":\s*"FeatureCollection"#', '"type": "FeatureCollection", "@epos-style": ' . $eposStyle, $geoserverData);
    
// Return the data to the client
    returnData($eposData);


 /**
 * Processes the query string:
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
    // remove empty CQL filters
    $CQL = preg_replace('#\w+=\'\?\'\sAND\s#', '', $cqlMatches[2]);
    // change request format from "json" (as required by the portal for visibility in map and table view) to
    // application/json, for correct processing by Geoserver
    $baseQuery = preg_replace('#outputFormat=json#', 'outputFormat=application/json', $cqlMatches[1]);
    // reformat comparative and range filters
    $CQL = convertComparativeAndRangeFilters($CQL);
    
    // if only one geometry column is specified, a simple replacement of the bbox geometry-placeholder is sufficient,
    // else, a more elaborate concatenation is required.
    if (count($geometryColumns) == 1) {
        $CQL = str_replace("geometry", $geometryColumns[0], $CQL);
    } else {
        // extract bounding box
        preg_match('#bbox\(geometry,(.+)#', $CQL, $bboxMatches);
        // repleace the bbox geometry-placeholder with the first geometry and remove the latter from the array
        $CQL = str_replace("geometry", array_shift($geometryColumns), $CQL);//
        // open paranthesis before the first bbox-filter
        $CQL = str_replace("bbox", "(bbox", $CQL);
        // add bbox-filters for the remaining geometries 
        foreach ($geometryColumns as $geometryColumn) {
            $CQL .= " OR bbox(" . $geometryColumn . "," . $bboxMatches[1];
        }
        // close paranthesis after the last bbox (end of filter)
        $CQL .= ")"; 
    }

    // URL encode the remaining CQL filters
    $CQL = urlencode($CQL);
    // assemble query for Geoserver
    $geoserverQuery = $baseQuery . $CQL;

    return $geoserverQuery;
}


 /**
 * Subfunction to process comparative and range filters in the CQL-string:
 *   Detects </>/- in the filter parameter, like
 *        property='<XXXX'
 *        property='>XXXX'
 *        property='XXXX-YYYY'
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

        // Rule 3: Matches: prop='val1-val2'
        // Breakdown:
        // ([a-zA-Z0-9]+) : Match 1 - The property name
        // ='             : Literal characters
        // ([^'-]+)       : Match 2 - Start value (anything except quote or hyphen)
        // -              : The hyphen separator
        // ([^']+)        : Match 3 - End value (anything except quote)
        "/([a-zA-Z0-9]+)='([^'-]+)-([^']+)'/"
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
            throw new Exception("EPOS CSS Service: The query is not a valid WFS request. Please check your query carefully against the documentation!", 422);
        }
    } catch (Exception $ex) {
        header('Content-Type: text/html');
        echo('EXCEPTION - Code: ' . $ex->getCode() . "<br>");
        echo($ex->getMessage());
    }
}
?>