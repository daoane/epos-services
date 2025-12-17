<?php

// Data access by site

/**
 * Provides transfer functions as a ZIP-archive of individual JSON files. The function queries the
 * database for all requested site/data type-combinations and adds a JSON file to the ZIP-archive
 * for all queries that return data.
 * 
 * @param string $siteNames    Comma-separated list of site names.
 * @param string $dataTypes    Comma-separated list of data types.
 * @return string              Path to temporary ZIP-file/archive.
 * @throws Exception           When database cannot be opened.
 * @throws Exception           In case of database error.
 * @throws Exception           When ZIP-archive creating encounters an error.
 */
function tfSiteZIP ($siteNames, $dataTypes) {
    // get an arry of id -> sitename and an array of data types
    $idsA = getSiteIds(explode(',', $siteNames));
    $dataTypesA = (explode(',',$dataTypes));

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
        foreach ($idsA as $id => $siteName):
            foreach ($dataTypesA as $dataType):
                // define basic database query
                $sql =<<<EOF
                SELECT data::json FROM
                        (SELECT sitename, data FROM emtdamo.epos_data ed
                        LEFT JOIN emtdamo.epos_site es on ed.site_id = es.id
                        WHERE site_id = $id AND datatype = '$dataType');
                EOF;
                $ret = pg_query($db, $sql);
                if(!$ret) {
                    throw new Exception("Database error:" . pg_last_error($db), 500);
                }
                $row = pg_fetch_row($ret);
                // JSON is returned as string in a php array
                $json = $row[0];
                // add JSON string as file to the zip archive
                empty($json) ? : $zip->addFromString ($siteName . "_" . $dataType . ".json", $json);
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

}

/**
 * Provides transfer functions as a single JSON file. The function queries the database for
 * all requested sites and data types, and assembles all available data into a single
 * JSON file. 
 * 
 * @param string $siteNames    Comma-separated list of site names.
 * @param string $dataTypes    Comma-separated list of data types.
 * @return json
 * @throws                     Exception When database cannot be opened.
 * @throws                     Exception In case of database error.
 */
function tfSiteJSON ($siteNames, $dataTypes) {
    // get a string of database ids for sitenames
    $siteNamesA = explode(",", $siteNames); // array of site names to be used in getSiteIds function
    $ids = "(" . implode(",", array_keys(getSiteIds($siteNamesA))) . ")"; // get ids by extracting them from associative array id -> sitename that is returned by getSiteIds
    // convert datatypes from string to array and back, for inserting the required single quotation marks
    $dataTypesA = explode(",", $dataTypes);
    $dataTypes = "('" . implode("','", $dataTypesA) . "')";

    // connect to database
    $db = pg_connect(db_conf);
    if (!$db) {
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
    
?>