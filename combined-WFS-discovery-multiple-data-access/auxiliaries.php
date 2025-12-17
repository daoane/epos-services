<?php

// Auxiliary functions for the EPOS TCS GO MT-services

/**
 * The database tables use integer IDs as primary keys. In addition, the "sitename"
 * is unique and used to identify a site in the user interaction and the payload
 * of services. This function takes an indexed array of "sitename"s and returns an
 * indexed array of ids. The function does not take care of the order in the arrays.
 * 
 * @param array $siteNames   The names of the sites.
 * @return array             The ids (table PKs) of the sites.
 * @throws Exception         When database cannot be opened.
 * @throws Exception         In case of database error.
 */
function getSiteIds($siteNames) {
    // array to string conversion for database query
    $sitesStr = "('" . implode("', '", $siteNames) . "')";

    // connect to database
    $db = pg_connect(db_conf);
    if (!$db) {
        throw new Exception("Unable to open database.", 500);
    }

    // query the database
    $sql = <<<EOF
               SELECT id, sitename from emtdamo.epos_site
                   where sitename IN $sitesStr;
            EOF;

    $ret = pg_query($db, $sql);

    // Check whether query was successful and throw an exception if it was not
    if (!$ret) {
        throw new Exception("Database error:" . pg_last_error($db), 500);
    }

    // get the ids from the database and contruct id -> sitename array
    $ids = array_combine(pg_fetch_all_columns($ret, 0),pg_fetch_all_columns($ret, 1));
    
    // close database connection
    pg_close($db);

    return($ids);
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
            throw new Exception("EPOS MT Service: The query is not a valid WFS request. Please check your query carefully against the documentation!", 422);
        }
    } catch (Exception $ex) {
        header('Content-Type: text/html');
        echo('EXCEPTION - Code: ' . $ex->getCode() . "<br>");
        echo($ex->getMessage());
    }
}

/**
 * Test if the requested data types are allowed, i.e. part of the provided reference data types.
 * 
 * @param string $dataTypes             Comma-separated list of requested data types, to be tested.
 * @param string $referenceDatatypes    Comma-separated list of allowed data types, to test against.
 * @throws                              Exception 422 Unprocessable Entity in case a datatype does not match any of the reference data types.
 */

function checkDataTypes($dataTypes, $referenceDatatypes) {
    $dataTypesA = explode(",", $dataTypes);
    $referenceDatatypesA = explode(",", $referenceDatatypes);
    foreach($dataTypesA as $dataType) {
        if (!in_array($dataType, $referenceDatatypesA)) {
            throw new Exception("EPOS MT Service: The specified data type \"" . $dataType . "\" does not exist or is spelled incorrectly. Please check your query and the service documentation.", 422);
        }
        
    }
}

?>