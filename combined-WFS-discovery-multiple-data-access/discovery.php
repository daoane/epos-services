<?php

/**
 * The discovery service returns discovery information for MT sites by relaying the request to Geoserver and
 * returning the response.
 * 
 * @param string $uri               The URI extracted by decoding the request.
 * @param string $geoserverBaseURL  The base URL to the Geoserver instance (usually the URL to the admin page).
 * @return json
 */
function discovery ($uri, $geoserverBaseURL) {
    // replace mtservice.php?-base-URL with geoserver base-URL and change "geo+json" to "json" as geoserver does not allow the former
    $request = $geoserverBaseURL . '/eposgo/ows?' . preg_replace('#^.*.php\?#', '', preg_replace('#geo\+#', '', $uri));
    // execute the query and test whether it was successful
    $response = file_get_contents($request);
    // test whether the response contains data or an error
    geoserverResponseCheck($response);
    
    // Part 2 of fix for Geoserver JSON array of strings workaround:
    // decode json, transform the comma-separated string in property datatype to a PHP array, encode json
    $responseA = json_decode($response, true);
    foreach($responseA["features"] as $key => $value) {
        $responseA["features"][$key]["properties"]["Datatypes"] = explode(",",$value["properties"]["Datatypes"]);
    }
    $jsonOut = json_encode($responseA);
    
    return $jsonOut;
}

?>