<?php
// This is the central script for the EPOS TCS Geomagnetic Observation MT services (".../mtService.php?")


include_once "auxiliaries.php";

// Define Geoserver base URL for requests
    $geoserverBaseURL = 'https://someURL/geoserver';
    
// Read database connection parameters and define as constant
    $db_conf_path = "pathToLocalFolder/eposgoPgConfig.php";
    try {
       if(!file_exists($db_conf_path))
         throw new Exception ('The configuration file does not exist');
       else
         require_once($db_conf_path);
    }
    catch(Exception $ex) {
       echo "Message : " . $ex->getMessage();
    }
    define("db_conf", $host . ' ' . $port . ' ' . $dbname . ' ' . $credentials);


// Pre-process the incoming URL and call the getService function to evaluate the content of the request's payload
    $uri = rawurldecode(filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL)); //assign query string to variable
    getService($uri, $geoserverBaseURL);


    
/**
 * getService evaluates the $uri content and extracts relevant variables from the payload of the request. It then calls the appropriate function for composing the payload
 * of the response to the request. Finally, it sets the correct headers and transmits the response.
 * 
 * @param string $uri                The decoded URI of the incoming request.
 * @param string $geoserverBaseURL   The URL that is used for Geoserver requests.
 * @throws Exception                 If the type of request could not be identified from the URI.
 */    
function getService ($uri, $geoserverBaseURL) {
    // comma-separated string of all available transfer function datatypes
    $tfDataTypes = "IMP,TIP,HTF";
    // set base file name of the returned file (without extension)
    $fileName = "EPOS_GO-transfer_functions_" . date('Ymd_His');
    // evaluate URL and call appropriate functions - the order of the evaluation is important!
    try {
        switch (true) {
            
        // Discovery service
            case preg_match("#outputformat=geo\+json#i", $uri) OR preg_match("#outputformat=application\/geo\+json#i", $uri):
                //echo "Discovery service";
                include "discovery.php";
                $data = discovery($uri, $geoserverBaseURL);

                // Set HTTP headers and send the data
                header('Content-type: application/JSON');
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching
                echo $data;
                break;

        // Data access by site
            //TF site download in ZIP (zipped individual JSON files), with datatypes specified
            case preg_match ("#sitename=([^&]+)#i", $uri, $siteNames) AND preg_match ("#datatype=([^&]+)#i", $uri, $dataTypes) AND (preg_match("#outputformat=zip#i", $uri) OR preg_match("#outputformat=application\/zip#i", $uri)):
                //echo "TF site download in ZIP (zipped individual JSON files, with datatypes specified)";
                include "tfDataBySite.php";
                // check whether all specified data types exist and are correctly spelled
                checkDataTypes($dataTypes[1], $tfDataTypes);
                $zipFile = tfSiteZIP($siteNames[1], $dataTypes[1]);

                // Set HTTP headers for file download
                header('Content-Type: application/zip'); // Indicates the content is a zip file
                header('Content-Disposition: attachment; filename="' . $fileName . '.zip"'); // Forces download and suggests filename
                header('Content-Length: ' . filesize($zipFile)); // Provides the file size for download progress
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching

                // Read the zip file and output its contents as response to the web request. Clean up the temporary file.
                readfile($zipFile);
                unlink($zipFile);
                break;

            //TF site download in ZIP (zipped individual JSON files), with datatypes missing - provides all datatypes
            case preg_match ("#sitename=([^&]+)#i", $uri, $siteNames) AND (preg_match("#outputformat=zip#i", $uri) OR preg_match("#outputformat=application\/zip#i", $uri)):
                //echo "TF site download in ZIP (zipped individual JSON files), with datatypes missing - provides all datatypes";
                include "tfDataBySite.php";
                $zipFile = tfSiteZIP($siteNames[1], $tfDataTypes);

                // Set HTTP headers for file download
                header('Content-Type: application/zip'); // Indicates the content is a zip file
                header('Content-Disposition: attachment; filename="' . $fileName . '.zip"'); // Forces download and suggests filename
                header('Content-Length: ' . filesize($zipFile)); // Provides the file size for download progress
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching

                // Read the zip file and output its contents as response to the web request. Clean up the temporary file.
                readfile($zipFile);
                unlink($zipFile);
                break;            

            //TF site download in JSON, with datatypes specified
            case preg_match ("#sitename=([^&]+)#i", $uri, $siteNames) AND preg_match ("#datatype=([^&]+)#i", $uri, $dataTypes) AND (preg_match("#outputformat=json#i", $uri) OR preg_match("#outputformat=application\/json#i", $uri)):
                //echo "TF site download in JSON, with datatypes specified";
                include "tfDataBySite.php";
                // check whether all specified data types exist and are correctly spelled
                checkDataTypes($dataTypes[1], $tfDataTypes);
                $data = tfSiteJSON($siteNames[1], $dataTypes[1]);
                
                // Set HTTP headers and send data
                header('Content-type: application/JSON');
                header('Content-Disposition: inline; filename="' . $fileName . '.json"');
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching
                echo $data;
                break;

            //TF site download in JSON, with datatypes missing - provides all datatypes
            case preg_match ("#sitename=([^&]+)#i", $uri, $siteNames) AND (preg_match("#outputformat=json#i", $uri) OR preg_match("#outputformat=application\/json#i", $uri)):
                //echo "TF site download in JSON, with datatypes missing - provides all datatypes";
                include "tfDataBySite.php";
                $data = tfSiteJSON($siteNames[1], $tfDataTypes);
                
                // Set HTTP headers and send data
                header('Content-type: application/JSON');
                header('Content-Disposition: inline; filename="' . $fileName . '.json"');
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching
                echo $data;
                break;

        // Data access based on the WFS discovery query
            // TF download in ZIP (zipped individual JSON files) according to discovery request, with datatypes specified
            case preg_match ("#service=WFS#i", $uri) AND preg_match ("#datatype=([^&]+)#i", $uri, $dataTypes) AND (preg_match("#outputformat=zip#i", $uri) OR preg_match("#outputformat=application\/zip#i", $uri)):
                //echo "TF download in ZIP (zipped individual JSON files) according to discovery request, with datatypes specified";
                include "tfDataFromDiscovery.php";
                // check whether all specified data types exist and are correctly spelled
                checkDataTypes($dataTypes[1], $dataTypes[1]);
                $zipFile = tfDownloadZIP($uri, $geoserverBaseURL, $dataTypes[1]);

                // Set HTTP headers for file download
                header('Content-Type: application/zip'); // Indicates the content is a zip file
                header('Content-Disposition: attachment; filename="' . $fileName . '.zip"'); // Forces download and suggests filename
                header('Content-Length: ' . filesize($zipFile)); // Provides the file size for download progress
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching

                // Read the zip file and output its contents as response to the web request. Clean up the temporary file.
                readfile($zipFile);
                unlink($zipFile);
                break;

            // TF download in ZIP (zipped individual JSON files) according to discovery request, with datatypes missing - provides all datatypes
            case preg_match ("#service=WFS#i", $uri) AND (preg_match("#outputformat=zip#i", $uri) OR preg_match("#outputformat=application\/zip#i", $uri)):
                // echo "TF download in ZIP (zipped individual JSON files) according to discovery request, with datatypes missing - provides all datatypes";
                include "tfDataFromDiscovery.php";
                $zipFile = tfDownloadZIP($uri, $geoserverBaseURL, $tfDataTypes);

                // Set HTTP headers for file download
                header('Content-Type: application/zip'); // Indicates the content is a zip file
                header('Content-Disposition: attachment; filename="' . $fileName . '.zip"'); // Forces download and suggests filename
                header('Content-Length: ' . filesize($zipFile)); // Provides the file size for download progress
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching

                // Read the zip file and output its contents as response to the web request. Clean up the temporary file.
                readfile($zipFile);
                unlink($zipFile);
                break;

            // TF download in JSON files according to discovery request, with datatypes specified
            case preg_match ("#service=WFS#i", $uri) AND preg_match ("#datatype=([^&]+)#i", $uri, $dataTypes) AND (preg_match("#outputformat=json#i", $uri) OR preg_match("#outputformat=application\/json#i", $uri)):
                //echo "TF download in JSON files according to discovery request, with datatypes specified";
                include "tfDataFromDiscovery.php";
                // check whether all specified data types exist and are correctly spelled
                checkDataTypes($dataTypes[1], $tfDataTypes);
                $data = tfDownloadJSON($uri, $geoserverBaseURL, $dataTypes[1]);
                
                // Set HTTP headers and send data
                header('Content-type: application/JSON');
                header('Content-Disposition: inline; filename="' . $fileName . '.json"');
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching
                echo $data;
                break;

            // TF download in JSON files according to discovery request, with datatypes missing - provides all datatypes
            case preg_match ("#service=WFS#i", $uri) AND (preg_match("#outputformat=json#i", $uri) OR preg_match("#outputformat=application\/json#i", $uri)):
                //echo "TF download in JSON files according to discovery request, with datatypes missing";
                include "tfDataFromDiscovery.php";
                $data = tfDownloadJSON($uri, $geoserverBaseURL, $tfDataTypes);
                
                // Set HTTP headers and send data
                header('Content-type: application/JSON');
                header('Content-Disposition: inline; filename="' . $fileName . '.json"');
                header('Pragma: no-cache'); // Prevents caching
                header('Expires: 0'); // Prevents caching
                echo $data;
                break;

            // return error when all matches are false
            default:
                   throw new Exception("EPOS MT Services: The request was not recogised by the allocator. Please check the URL against the service documentation.", 422);
        }
    } catch (Exception $ex) {
        echo('EXCEPTION - Code: ' . $ex->getCode() . "<br>");
        echo($ex->getMessage());
    }
}
?>
