<?php

// Data access based on the WFS discovery query

/**
 * Provides a zip-file/archive of transfer functions with individual JSON files per requested site and per data type.
 * 
 * @param string $uri                 The URL of the original WFS request for which the data are requested.
 * @param string $geoserverBaseURL    The base URL to the Geoserver instance (usually the URL to the admin page).
 * @param string $dataTypes           Comma-separated list of data types.
 * @return string                     Path to temporary ZIP-file/archive.
 * @throws Exception                  When database cannot be opened.
 * @throws Exception                  In case of database error.
 */
function tfDownloadZIP ($uri, $geoserverBaseURL, $dataTypes) {
    // convert datatypes from string to array and back, for inserting the required single quotation marks
    $dataTypesA = explode(",", $dataTypes);
    $dataTypes = "('" . implode("','", $dataTypesA) . "')";
    
    // get ids of the sites
    $idsA = siteIdfromGeoserver($uri, $geoserverBaseURL);

    // Store the zip file in the system's temporary directory for security and cleanup
    $zipFilePath = sys_get_temp_dir() . '/tempTfData.zip';

    // Create a new ZipArchive instance
    $zip = new ZipArchive();

    // Open the zip file for writing. If it doesn't exist, it will be created.
    // ZIPARCHIVE::CREATE creates the archive if it doesn't exist.
    // ZIPARCHIVE::OVERWRITE overwrites the archive if it already exists.
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {

        // connect to database
        $db = pg_connect(db_conf);
        if (!$db) {
            throw new Exception("Unable to open database.", 500);
        }

        // loop through sites and datatypes
        foreach ($idsA as $id => $sitename):
            foreach ($dataTypesA as $dataType):
                // define basic database query
                $sql = "SELECT json_agg(data::json) FROM emtdamo.epos_data WHERE site_id = $id AND datatype = '$dataType'";

                $ret = pg_query($db, $sql);
                if(!$ret) {
                    throw new Exception("Database error:" . pg_last_error($db), 500);
                }
                $row = pg_fetch_row($ret);
                // JSON is returned as string in a php array
                $json = $row[0];
                // add JSON string as file to the zip archive
                empty($json) ? : $zip->addFromString ($sitename . "_" . $dataType . ".json", $json);
            endforeach;
        endforeach;

        // Close the data base connection and the zip file
        pg_close($db);
        $zip->close();

        return($zipFilePath);

    } else {
        // Handle cases where the zip archive could not be opened/created
        throw new Exception("Failed to create zip archive.", 500);
    }

    return $row[0];
}

/**
 * Provides transfer functions as a single JSON file, based on requested site names and data types. 
 * 
 * @param string $uri                 The URL of the original WFS request for which the data are requested.
 * @param string $geoserverBaseURL    The base URL to the Geoserver instance (usually the URL to the admin page).
 * @param string $dataTypes           Comma-separated list of data types.
 * @return json
 * @throws Exception                  When database cannot be opened.
 * @throws Exception                  In case of database error.
*/
function tfDownloadJSON ($uri, $geoserverBaseURL, $dataTypes) {
    // convert datatypes from string to array and back, for inserting the required single quotation marks
    $dataTypesA = explode(",", $dataTypes);
    $dataTypes = "('" . implode("','", $dataTypesA) . "')";
  
    // get sitename-id array of the sites
    $idsA = siteIdfromGeoserver($uri, $geoserverBaseURL);

    // prepare ids as string for PostgreSQL query
    $ids = "(" . implode(",", array_keys($idsA)) . ")";

    // connect to database
    $db = pg_connect(db_conf);
    if(!$db) {
       throw new Exception("Unable to open database.", 500);
    }

    // query the database
    $sql =<<<EOF
        SELECT json_agg(data::json) FROM
                (SELECT sitename, data FROM emtdamo.epos_data ed
                LEFT JOIN emtdamo.epos_site es on ed.site_id = es.id
                WHERE site_id IN $ids AND datatype IN $dataTypes
                ORDER BY sitename, datatype);
    EOF;

    $ret = pg_query($db, $sql);

    if(!$ret) {
       throw new Exception("Database error:" . pg_last_error($db), 500);
    }
    $row = pg_fetch_row($ret);

    // close database connection
    pg_close($db);

    return $row[0];
}

/**
 * The function queries Geoserver with a WFS query to returns the ids of the sites that were discovered.
 *  
 * @param string $uri                 The URL of the original WFS request for which the data are requested.
 * @param string $geoserverBaseURL    The base URL to the Geoserver instance (usually the URL to the admin page).
 * 
 * @return array                      Array of sitename -> id.
 */
function siteIdfromGeoserver ($uri, $geoserverBaseURL) {
    // reformat and concatenate the URL
    $query1 = preg_replace('/\/mtService.php\?/', '', $uri); // remove prefix (filename)
    $query2 = preg_replace('/eposgo:MTSiteView/', 'eposgo%3AEposSiteId', $query1); // replace the typeName
    $query3 = preg_replace('/&dataType=[^&]*/', '', $query2); // remove the datatypes filter, if it is present
    $query4 = preg_replace('/\/zip/i', '/json', $query3); // replace the zip output format with json for the Geoserver request (for queries that request zipped data)
    $url = $geoserverBaseURL . '/eposgo/ows?' . $query4; // concatenate the full URL

    // get the sites from geoserver and test for success
    $response = file_get_contents($url); // send request to geoserver and retrieve data
    // test whether the response contains data or an error
    geoserverResponseCheck($response);
    // decode the json, extract the ids (PKs) to an array, and convert the id array to a string that is conform to the expected json-array
    $arr = json_decode($response, true);

    $idsA = array();
    foreach($arr["features"] as $feature) {
        $idsA[$feature["id"]] = $feature["sitename"];
    }
    
    return $idsA;
}

?>