<?php
print "Running neon import";
require_once('output_file.php');
require_once('database.php');
global $mysql;
global $log;
global $stations;
global $plants;
global $indicies;

$stations = array();
$plants = array();
$log = new OutputFile(__DIR__ . "/output.txt");
$indicies = array("observations" => array(), "plants" => array(), "updates" => array());

$mysql = null;
$params = parse_ini_file(__DIR__ . '/config.ini');
define('DEBUG',1);
define('EMPTY_SESSION_ID', -1);
define('NEON_MASTER_SITE_OWNER_ID', -5);



//Not to be confused this isn't a flag, the actual ID for square meters
//in the usanpn2.Units table is 1
define('SQUARE_METERS_DB_ID',1);

try{
    $mysql = new Database("mysql",
            $params['mysql_host'], 
            $params['mysql_user'],
            $params['mysql_pw'],
            $params['mysql_db'],
            $params['mysql_port'], 
            $log
            );
    
    
    $mysql->beginTransaction();


}catch(Exception $ex){
    $log->write("There was an error establishing database connections. Terminating job.");
    $log->write(print_r($ex, true));
    die();
}



try{
    
    

//    parseStationsAndPlants();
    parseObservations();
    logImport();
    
    if(DEBUG){
        $mysql->rollback();
    }else{
        $mysql->commit();
    }
    
}catch(Exception $ex){
    $log->write("Unexpected error.");
    $log->write(print_r($ex, true));
    
    $mysql->rollback();    
}


function logImport(){
    $fhandle = fopen("last_execute.txt",'w+');    
    fwrite($fhandle, time());    
    fclose($fhandle);    
}

function getPreviousImportTime(){    
    $previous_time = file_get_contents("last_execute.txt");    
    return ((int)$previous_time);    
}




function findAndCleanField($name, $cells, $headers, $file){
    global $indicies;
    
    $indx = null;
    
    if(array_key_exists($name, $indicies[$file])){
        $indx = $indicies[$file][$name];
    }else{
        $indx = findColNumFromName($name, $headers);
        $indicies[$file][$name] = $indx;
    }
        
    $field = $cells[$indx];
    
    
    return str_replace("\"", "", $field);
}


function findColNumFromName($name, $headers){
    $indx = -1;
    $i=0;
    foreach($headers as $header){
        if($header == $name){
            $indx = $i;
            break;
        }
        $i++;
    }
    
    return $indx;
}


function observationExists($plant_id, $date, $observer_id, $phenophase_id){
    global $mysql;
    
    $dateObj = new DateTime($date);
    $date =  $dateObj->format("Y-m-d");
    $existing_obs_id = null;
    
    $query = "SELECT Observation_ID FROM usanpn2.tmp_Observation  " .
            "WHERE Individual_ID = " . $plant_id . " " .
            "AND Observation_Date = '" . $date . "' " .
            "AND Observer_ID = " . $observer_id . " " .
            "AND Phenophase_ID = " . $phenophase_id;
    
    $results = $mysql->getResults($query);
    
    while($row = $results->fetch()){
        $existing_obs_id = $row['Observation_ID'];
    }
    
    return $existing_obs_id;
    
}



function parseObservations(){
    global $log;
    global $mysql;
    

    global $plants;    
        
    $fhandle = fopen('./data/phe_statusintensity.csv','r');    
    $neon_dataset_id = null;
    
    $previous_time = getPreviousImportTime();

    //$headers = explode(",",array_shift($rows));
    $headers = explode(",", fgets($fhandle));
    
    for($i=0;$i<count($headers);$i++){
        $headers[$i] = str_replace("\"", "", $headers[$i]);
    }
    
    
    $query = "SELECT Dataset_ID FROM usanpn2.Dataset WHERE Dataset.Dataset_Name = 'NEON'";
    $results = $mysql->getResults($query);
    while($row = $results->fetch()){
        $neon_dataset_id = $row['Dataset_ID'];
    }
    
    //foreach($rows as $row){
    while(! feof($fhandle)){
        
        $cells = explode(",", fgets($fhandle));
        if(count($cells) == 0) continue;
        
        $neon_obs_id = findAndCleanField("uid", $cells, $headers, "observations");
        
        $edited_date = findAndCleanField("editedDate", $cells, $headers, "observations");
        $edited_date = new DateTime($edited_date);        
        
        /**
         * If the record is old and hasn't been updated since the last import
         * it can be skipped completely.
         */
        if($edited_date->getTimestamp() < $previous_time){
            $log->write("Timestamp older than last import date. Skipping. NEON ID: " . $neon_obs_id);
            continue;
        }        
        
        
        $plant_id = findAndCleanField("individualID", $cells, $headers, "observations");   
        $plant_in_plants_array = array_key_exists($plant_id, $plants);
        $the_plant = ($plant_in_plants_array && $plants[$plant_id] != null) ? $plants[$plant_id] : null;
        
        if(!$plant_in_plants_array && !$the_plant){
            $the_plant = plantExists($plant_id);
            $plants[$plant_id] = $the_plant;
        }

        if(!$the_plant){
            $log->write("Tried to update/insert observation but could not find plant: " . $plant_id);
            continue;
        }

        
        $date = findAndCleanField("date", $cells, $headers, "observations");

        
        $phenophase_name = findAndCleanField("phenophaseName", $cells, $headers, "observations");
        
        if(isIgnoredPhenophase($the_plant, $phenophase_name)){
            $log->write("Skipping record. Found a non-applicable species/phenophase.");
            continue;
        }
        
        

        $submitted_observer_neon_id = findAndCleanField("recordedBy", $cells, $headers, "observations");
        $observer_neon_id = findAndCleanField("measuredBy", $cells, $headers, "observations");        

        $submitted_person_id = resolvePersonID($submitted_observer_neon_id);
        $observer_id = resolvePersonID($observer_neon_id);        
        
        $protocol_data = getNPNPhenophaseAndProtocolID($phenophase_name, $the_plant, $date, $neon_obs_id);        
        $phenophase_id = $protocol_data[0];
        $protocol_id = $protocol_data[1];        
       
        
        $status = findAndCleanField("phenophaseStatus", $cells, $headers, "observations");
                
        $intensity_amount = findAndCleanField("phenophaseIntensity", $cells, $headers, "observations");
        $comments = findAndCleanField("remarks", $cells, $headers, "observations");

        $observation_group_id = resolveObservationGroup($the_plant, $date, $observer_id);
        
        if(!$observation_group_id){
            $log->write("Error inserting record; unable to resolve obs group id");
            $log->write($row);
        }
        
        //$submission_id = resolveSubmission($the_plant, $date, $observer_id, $submitted_person_id, $edited_date->format("Y-m-d"));
//TODO CHANGE ME BACK TESTING ONLY        
        $submission_id = 1;
        
        if(!$submission_id){
            $log->write("Error inserting record; unable to resolve submission id");
            $log->write($row);
        }        
        
       

        if($comments && $comments == "NA" && !empty(trim($comments)) && $comments != ""){
            $comments = $neon_obs_id . ": " . $comments;            
        }else{
            $comments = $neon_obs_id;
        }
        
        if($status && $status == "no"){
            $status = 0;
        }else if($status && $status == "yes"){
            $status = 1;
        }else if($status && $status == "uncertain"){
            $status = -1;
        }else{
            continue;
        }       
        

        
        if($intensity_amount && !empty($intensity_amount)){
            $intensity_data = getNPNIntensityData($phenophase_id, $intensity_amount, $the_plant, $date, $intensity_amount, $neon_obs_id);
            $abundace_category = $intensity_data[1];
            $intensity_id = $intensity_data[0];
        }else{
            $abundace_category = null;
            $intensity_id = null;
        }
        
        /**
         * This is this way because the intensity ID values could be NULL in the
         * database (or from a mistake querying the database) OR they could be
         * NULL in the data file and in either case, the NULL needs to be 
         * represented as the text 'NULL' in the query when it's inserted into
         * into the database.
         */
        if(!$abundace_category){
            $abundace_category = "NULL";
        }
        
        if(!$intensity_id){
            $intensity_id = "NULL";
        }        
        

        
        /**
         * With all requisite data available now check to see if the record already exists.
         * If it does update it, otherwise create it.
         */
        $existing_obs_id = observationExists($the_plant->getNPNID(), $date, $observer_id,$phenophase_id);
        //$existing_obs_id = 1;

        if($existing_obs_id){

            $log->write("Found existing observation, with updated timestamp. Attempting update. NEON ID: " . $neon_obs_id);

            try{

                $query = "UPDATE usanpn2.tmp_Observation SET " .
                        "Observation_Extent = " . $status . ", " . 
                        "Comment = '" . $comments . "', " .
                        "Abundance_Category_Value = " . $intensity_id . " " . 
                        "WHERE Observation_ID = " . $existing_obs_id;                

                $mysql->runQuery($query);

            }catch(Exception $ex){
                $log->write("Failed to update existing observation record.");
                $log->write(print_r($ex,true));
            }

            continue;
        }        
        
        

        
        $query = "INSERT INTO usanpn2.tmp_Observation (Observer_ID, Submission_ID, Phenophase_ID, Observation_Extent, `Comment`, Individual_ID, "
                . "Observation_Group_ID, Protocol_ID, Abundance_Category, Abundance_Category_Value, Observation_Date, Deleted) VALUES (" .
                $observer_id . ", " .
                $submission_id . ", " .
                $phenophase_id . ", " .
                $status . ", " . 
                "'" . $comments . "', " .
                $the_plant->getNPNID() . ", " . 
                $observation_group_id . ", " .
                $protocol_id . ", " .
                $abundace_category . ", " .
                $intensity_id . ", " .
                "'" . $date . "'," .
                "0)";
        
        $mysql->runQuery($query);
        $obs_id = $mysql->getId();
        
        
        $query = "INSERT INTO usanpn2.tmp_Dataset_Observation (Dataset_ID, Observation_ID) VALUES (" . $neon_dataset_id . ", " . $obs_id . ")";
        $mysql->runQuery($query);
        
        
    }
    
    fclose($fhandle);
    
}


function resolvePersonID($neon_person_id){
    

    global $mysql;
    global $log;
    
    $npn_person_id = null;
    
    try{
        $query = "SELECT Person_ID FROM usanpn2.Person WHERE UserName = '" . $neon_person_id . "'";
        $results = $mysql->getResults($query);
        
        while($row = $results->fetch()){
            $npn_person_id = $row['Person_ID'];
        }
        
        if(!$npn_person_id){
            
            $query = "SELECT MIN(Person_ID) `min` FROM usanpn2.Person";
            
            $results = $mysql->getResults($query);
            
            while($row = $results->fetch()){
                $person_id = $row['min'] - 1;
            }
            
            $query = "INSERT INTO usanpn2.Person (Person_ID, Create_Date, Comments,Load_Key, Active, UserName) VALUES (" .
                    $person_id . ", " . 
                    "'" . (new DateTime())->format("Y-m-d") . "', " .
                    "''," .
                    "'NEON_" . $neon_person_id . "'," .
                    "0," .
                    "'" . $neon_person_id . "'" .
                    ")";
                    
            $mysql->runQuery($query);
            $npn_person_id = $mysql->getId();
            
            if(!$npn_person_id){
                throw new Exception("There was an error attempting to generate a new person id from NEON ID:" . $neon_person_id);
            }
            
        }
        
    } catch (Exception $ex) {
        $log->write("There was a problem resolving person id.");
        $log->write(print_r($ex, true));
        $npn_person_id = null;

    }
    
    return $npn_person_id;
    
}

function resolveSubmission($plant, $date, $npn_observer_id, $edited_user_id, $edited_date){
    
    global $mysql;
    global $log;
    
    $station_id = null;
    $submission_id = null;
    
    try{
        
        if($plant){
            $station_id = $plant->getStationID();
            
            if(!$station_id){
                throw new Exception("Station ID not found for plant, could not resolve obs group.");
            }
            
        }else{
            throw new Exception("Could not resolve obs group because plant was not found in db.");
        }
        
        $query = "SELECT Submission.Submission_ID FROM usanpn2.tmp_Submission `Submission`
                    LEFT JOIN usanpn2.tmp_Observation `Observation`
                    ON Observation.Submission_ID = Submission.Submission_ID
                    LEFT JOIN Station_Species_Individual
                    ON Station_Species_Individual.Individual_ID = Observation.Individual_ID
                    WHERE Station_ID = " . $station_id . " " .
                    "AND Observation_Date = '" . $date . "' " .
                    "AND Observer_ID = " . $npn_observer_id;
        
        $results = $mysql->getResults($query);        

        while($row = $results->fetch()){
            $submission_id = $row['Submission_ID'];
        }

        if(!$submission_id){
            $query = "INSERT INTO usanpn2.tmp_Submission (Session_ID, Submission_DateTime, Create_Person_ID, Update_DateTime, Update_Person_ID) VALUES (" .
                    EMPTY_SESSION_ID . ", " .
                    "'" . $date . "', " .
                    $npn_observer_id . ", " . 
                    "'" . $edited_date . "', " .
                    $edited_user_id . 
                    ")";
            
            $mysql->runQuery($query);
            $submission_id = $mysql->getId();
            
            if(!$submission_id){
                throw new Exception("Submission is null after insert.");
            }
        }
        
        
                
    } catch (Exception $ex) {
        $log->write("Error resolving Submission.");
        $log->write(print_r($ex,true));
        $submission_id = null;        
    }
    
    return $submission_id;
}


function resolveObservationGroup($plant, $date, $observer_id){
    
    global $mysql;
    global $log;    
    
    $station_id = null;
    $obs_group_id = null;
    
    try{
        

        
        if($plant){
            $station_id = $plant->getStationID();
            
            if(!$station_id){
                throw new Exception("Station ID not found for plant, could not resolve obs group.");
            }
            
        }else{
            throw new Exception("Could not resolve obs group because plant was not found in db.");
        }
        
        $query = "SELECT Observation_Group_ID FROM tmp_Observation_Group "
                . "WHERE Observer_ID = " . $observer_id . " "
                . "AND Station_ID = " . $station_id . " "
                . "AND Observation_Group_Date = '" . $date . "'";
        
        $results = $mysql->getResults($query);        

        while($row = $results->fetch()){
            $obs_group_id = $row['Observation_Group_ID'];
        }

        if(!$obs_group_id){
            $query = "INSERT INTO usanpn2.tmp_Observation_Group (Observation_Group_Date, Observer_ID, Station_ID) VALUES ("
                    . "'" . $date . "', "
                    . $observer_id . ", "
                    . $station_id .
                    ")";
            
            $mysql->runQuery($query);
            $obs_group_id = $mysql->getId();
            
            if(!$obs_group_id){
                throw new Exception("Obs Group ID is null after insert.");
            }
        }
        
    } catch (Exception $ex) {
        $log->write("Error resolving observation group.");
        $log->write(print_r($ex,true));
        $obs_group_id = null;
    }
    
    return $obs_group_id;
    
}


function getNPNIntensityData($phenophase_id, $intensity_amount, $the_plant, $date, $neon_obs_id){
    global $mysql;
    global $log;
    
    $intensity_id = null;
    $category_id = null;
    
    # Special exception where NEON was consistently recording intensity for some plant/phenophase
    # that doesn't actually use any kind of intensity measure.
    
    if($the_plant->getUSDASymbol() == "ARNU2" && $phenophase_id == 488){
        return array(null,null);
    }
    
    if($intensity_amount == ">= 95%"){
        $intensity_amount = "95% or more";
    }
    
    if($intensity_amount == "< 3"){
        $intensity_amount = "Less than 3";
    }
    
    if($intensity_amount == "< 5%"){
        $intensity_amount = "Less than 5%";
    }
    
    if($intensity_amount == "< 25%"){
        $intensity_amount = "Less than 25%";        
    }
    
    if($intensity_amount == "101 to 1000"){
        $intensity_amount = "101 to 1,000";
    }
    
    if($intensity_amount == "1001 to 10000"){
        $intensity_amount = "1,001 to 10,000";
    }
    
    if($intensity_amount == "> 10000"){
        $intensity_amount = "More than 10,000";
    }
    
    if($intensity_amount == "NA"){
        return array(null,null);
    }
    
    try{
        $query = "SELECT Abundance_Values.Abundance_Value_ID,Abundance_Category.Abundance_Category_ID 
        FROM usanpn2.Species_Specific_Phenophase_Information
        LEFT JOIN usanpn2.Abundance_Category
        ON Abundance_Category.Abundance_Category_ID = Species_Specific_Phenophase_Information.Abundance_Category
        LEFT JOIN usanpn2.Abundance_Category_Abundance_Values
        ON Abundance_Category_Abundance_Values.Abundance_Category_ID = Abundance_Category.Abundance_Category_ID
        LEFT JOIN usanpn2.Abundance_Values
        ON Abundance_Values.Abundance_Value_ID = Abundance_Category_Abundance_Values.Abundance_Value_ID
        WHERE Species_Specific_Phenophase_Information.Species_ID = " . $the_plant->getSpeciesID() . "
        AND Species_Specific_Phenophase_Information.Phenophase_ID = " . $phenophase_id . "
        AND Abundance_Values.Short_Name = '" . $intensity_amount . "'
        AND 
            ('" . $date . "' BETWEEN Species_Specific_Phenophase_Information.Effective_Datetime AND Species_Specific_Phenophase_Information.Deactivation_Datetime
            OR '" . $date . "' >= Species_Specific_Phenophase_Information.Effective_Datetime AND Species_Specific_Phenophase_Information.Deactivation_Datetime IS NULL
            )";
        
        $results = $mysql->getResults($query);        

        while($row = $results->fetch()){
            $intensity_id = $row['Abundance_Value_ID'];
            $category_id = $row['Abundance_Category_ID'];
        }

        if(!$intensity_id){
            
            $query = "SELECT Abundance_Values.Abundance_Value_ID,Abundance_Category.Abundance_Category_ID 
            FROM usanpn2.Species_Specific_Phenophase_Information
            LEFT JOIN usanpn2.Abundance_Category
            ON Abundance_Category.Abundance_Category_ID = Species_Specific_Phenophase_Information.Abundance_Category
            LEFT JOIN usanpn2.Abundance_Category_Abundance_Values
            ON Abundance_Category_Abundance_Values.Abundance_Category_ID = Abundance_Category.Abundance_Category_ID
            LEFT JOIN usanpn2.Abundance_Values
            ON Abundance_Values.Abundance_Value_ID = Abundance_Category_Abundance_Values.Abundance_Value_ID
            WHERE Species_Specific_Phenophase_Information.Species_ID = " . $the_plant->getSpeciesID() . "
            AND Species_Specific_Phenophase_Information.Phenophase_ID = " . $phenophase_id . "
            AND Abundance_Values.Short_Name = '" . $intensity_amount . "'
            AND Species_Specific_Phenophase_Information.Deactivation_Datetime IS NULL";

            $results = $mysql->getResults($query);        

            while($row = $results->fetch()){
                $intensity_id = $row['Abundance_Value_ID'];
                $category_id = $row['Abundance_Category_ID'];
            }                 
        }
        
        if(!$intensity_id){
            throw new Exception("Unable to find suitable intensity value id. NEON ID: " . $neon_obs_id);
        }
        
        if(!$category_id){
            throw new Exception("Unable to find suitable intensity category id. NEON ID: " . $neon_obs_id);
        }

        
        
    } catch (Exception $ex) {
        $log->write("Error finding an intensity ID for: " . $phenophase_id . "; Intensity Amount: " . $intensity_amount . "; Species_ID: " . $the_plant->getSpeciesID() .";date: " . $date . "; NEON ID: " . $neon_obs_id . "; Plant Name: " . $the_plant->getName());
        $log->write(print_r($ex, true));        
    }
    
    return array($intensity_id, $category_id);
}


function getNPNPhenophaseAndProtocolID($phenophase_name, $the_plant, $date, $neon_obs_id){

    global $mysql;
    global $log;
    
    $phenophase_id = null;
    $protocol_id = null;
    
    try{
        $query = "SELECT Phenophase.Phenophase_ID,Species_Protocol.Protocol_ID
            FROM usanpn2.Phenophase
            LEFT JOIN usanpn2.Protocol_Phenophase
            ON Protocol_Phenophase.Phenophase_ID = Phenophase.Phenophase_ID

            LEFT JOIN usanpn2.Species_Protocol
            ON Species_Protocol.Protocol_ID = Protocol_Phenophase.Protocol_ID

            LEFT JOIN usanpn2.Species
            ON Species.Species_ID = Species_Protocol.Species_ID

            WHERE Species.Species_ID = " . $the_plant->getSpeciesID() . "
            AND LOWER(IF(POSITION('(' IN Phenophase.Description)-1 = -1, Phenophase.Description, SUBSTR(Phenophase.Description, 1, POSITION('(' IN Phenophase.Description)-1))) = LOWER('" . $phenophase_name . "')
            AND Species_Protocol.Dataset_ID IS NULL
            AND 
            ('" . $date . "' BETWEEN Species_Protocol.Start_Date AND Species_Protocol.End_Date
            OR '" . $date . "' >= Species_Protocol.Start_Date AND Species_Protocol.End_Date IS NULL)";
        
        $results = $mysql->getResults($query);        

        while($row = $results->fetch()){
            $phenophase_id = $row['Phenophase_ID'];
            $protocol_id = $row['Protocol_ID'];
        }
        
        if(!$phenophase_id){
            
            $query = "SELECT Phenophase.Phenophase_ID,Species_Protocol.Protocol_ID
                FROM usanpn2.Phenophase
                LEFT JOIN usanpn2.Protocol_Phenophase
                ON Protocol_Phenophase.Phenophase_ID = Phenophase.Phenophase_ID

                LEFT JOIN usanpn2.Species_Protocol
                ON Species_Protocol.Protocol_ID = Protocol_Phenophase.Protocol_ID

                LEFT JOIN usanpn2.Species
                ON Species.Species_ID = Species_Protocol.Species_ID

                WHERE Species.Species_ID = " . $the_plant->getSpeciesID() . "
                AND LOWER(IF(POSITION('(' IN Phenophase.Description)-1 = -1, Phenophase.Description, SUBSTR(Phenophase.Description, 1, POSITION('(' IN Phenophase.Description)-1))) = LOWER('" . $phenophase_name . "')
                AND Species_Protocol.Dataset_ID IS NULL
                AND Species_Protocol.End_Date IS NULL";

            $results = $mysql->getResults($query);

            while($row = $results->fetch()){
                $phenophase_id = $row['Phenophase_ID'];
                $protocol_id = $row['Protocol_ID'];
            }            
        }
        
        if(!$phenophase_id){
            throw new Exception("Unable to find suitable phenophase id. NEON Record ID: " . $neon_obs_id);
        }
        
        if(!$protocol_id){
            throw new Exception("Unable to find suitable protocol id. NEON Record ID: " . $neon_obs_id);
        }        
        
    }catch(Exception $ex){
        $log->write("Error finding a phenophase/protocol for: " . $phenophase_name . "; Species_ID: " . $the_plant->getSpeciesID() .";date: " . $date . "; NEON ID: " . $neon_obs_id . "; Plant ID: " . $the_plant->getName());
        $log->write(print_r($ex, true));
    }
    
    return array($phenophase_id, $protocol_id);

    
}



function parseStationsAndPlants(){
    global $stations;
    global $plants;
    global $mysql;    
    global $log;
    
    //$data = file_get_contents("./data/phe_perindividual.csv");
    $fhandle = fopen("./data/phe_perindividual.csv", 'r');
    
    //$rows = explode("\n", $data);
    $headers = explode(",",fgets($fhandle));
    //$headers = explode(",",array_shift($rows));
    for($i=0;$i<count($headers);$i++){
        $headers[$i] = str_replace("\"", "", $headers[$i]);
    }
    while(! feof($fhandle)){
    //foreach($rows as $row){
        $cells = explode(",",fgets($fhandle));
        //$cells =  $row);
        if(count($cells) == 0) continue;
        
        $station_name = findAndCleanField("namedLocation", $cells, $headers, "plants");        
        $station_subtype = findAndCleanField("subtypeSpecification", $cells, $headers, "plants");
        $station_name = $station_name . " - " . $station_subtype;               
        
        $the_station = null;
        $the_plant = null;
        
        
        if(!array_key_exists($station_name, $stations)){
            
            $the_station = stationExists($station_name);
            
            if($the_station){
                
                $stations[$station_name] = $the_station;
                
            }else{
            
                try{
                    $the_station = new Station();
                    $the_station->name = $station_name;
                    $the_station->setLoadKey($station_name);
                    $the_station->setObserverID(NEON_MASTER_SITE_OWNER_ID);
                    $the_station->setLatitude(findAndCleanField("decimalLatitude", $cells, $headers, "plants"));
                    $the_station->setLongitude(findAndCleanField("decimalLongitude", $cells, $headers, "plants"));
                    $the_station->setElevation(findAndCleanField("elevation", $cells, $headers, "plants"));
                }catch(Exception $ex){

                    $log->write("Error initiating station: " . $station_name);
                    $log->write(print_r($ex,true));

                }

                $the_station->insert($mysql, $log);

                $stations[$station_name] = $the_station;
            }
            
        }else{
            $the_station = $stations[$station_name];
        }
        
        
        $plant_name = findAndCleanField("individualID", $cells, $headers, "plants");
        
        if(!array_key_exists($plant_name, $plants)){
            
            $the_plant = plantExists($plant_name);
            
            if($the_plant){
                $plants[$plant_name] = $the_plant;
                continue;
            }
            
            $sampleLatitude = findAndCleanField("sampleLatitude", $cells, $headers, "plants");
            $sampleLongitude = findAndCleanField("sampleLongitude", $cells, $headers, "plants");

            $transectMeter = findAndCleanField("transectMeter", $cells, $headers,"plants");
            $directionFromTransect = findAndCleanField("directionFromTransect", $cells, $headers, "plants");            
            
            $plant_comment = "" . (($sampleLatitude && $sampleLatitude != "NA" && $sampleLongitude && $sampleLongitude != "NA") ? $sampleLatitude . "," . $sampleLongitude : "") .
                    ($transectMeter && $transectMeter != "NA" && $directionFromTransect && $directionFromTransect != "NA") ? "transectMeter: " . $transectMeter . " directionFromTransect: " . $directionFromTransect : "";
            
            $the_plant = new Individual();
            $the_plant->setStationID($the_station->getNpn_id());
            
            $the_plant->setName(findAndCleanField("individualID", $cells, $headers, "plants"));
            $the_plant->setUSDASymbol(findAndCleanField("taxonID", $cells, $headers, "plants"));

            $the_plant->setSeqNum($the_station->getSpeciesSeqNum());
            $the_station->setSpeciesSeqNum($the_station->getSpeciesSeqNum()+1);
            $the_plant->setCreateDate(new DateTime());
            $the_plant->setComment($plant_comment);
            
            $species_id = findNPNSpeciesID(findAndCleanField("taxonID", $cells, $headers, "plants"));
            if($species_id){
                $the_plant->setSpeciesID($species_id);            
                $the_plant->insert($mysql, $log);
            }else{
                $the_plant = null;
            }
            
            $plants[$plant_name] = $the_plant;
            
        }else{
            $log->write("Found a redundant plant: " . $plant_name . "\n");
        }

    }
    
    fclose($fhandle);
    
    $fhandle = fopen("./data/phe_perindividualperyear.csv", 'r');
    //$data = file_get_contents("./data/phe_perindividualperyear.csv");
    
    //$rows = explode("\n", $data);

    //$headers = explode(",",array_shift($rows));
    $headers = explode(",",fgets($fhandle));
    for($i=0;$i<count($headers);$i++){
        $headers[$i] = str_replace("\"", "", $headers[$i]);
    }

    while(!feof($fhandle)){
    //foreach($rows as $row){
        $cells = explode(",",fgets($fhandle));
//        $cells = explode(",", $row);
        
        if(count($cells) == 0) continue;
        
        $patch = findAndCleanField("patchOrIndividual", $cells, $headers, "updates");       
        $plant_id = findAndCleanField("individualID", $cells, $headers, "updates");

        $the_plant = (array_key_exists($plant_id, $plants)) ? $plants[$plant_id] : null;
        if(!$the_plant){
            $log->write("Tried to update patch status for plant but plant ID not found in perindividual file: " . $plant_id);
            continue;
        }

        
        
        if( ($patch && $patch == "Patch") && $the_plant->getPatch() != 1 ){

            $the_plant->setPatch(1);
            $the_plant->setPatchSize(findAndCleanField("patchSize", $cells, $headers, "updates"));
            $the_plant->setPatchSizeUnitsID(SQUARE_METERS_DB_ID);
            
            $the_plant->updatePatchStatus($mysql, $log);
                        
        }else if((!$patch || $patch != "Patch") && $the_plant->getPatch() == 1){
            $the_plant->setPatch(0);
            $the_plant->setPatchSize(null);
            $the_plant->setPatchSizeUnitsID(null); 
            
            $the_plant->updatePatchStatus($mysql, $log);
        }
    }
    
    fclose($fhandle);
    
    
}


function isIgnoredPhenophase($plant, $phenophase_name){
    
    $ignore_matrix = array(
        "ARNU2" => array(
            "breaking leaf buds",
            "falling leaves",
            "colored leaves",
            "increasing leaf size"
        ),
        "EPVI" => array(
            "young leaves",
            "breaking leaf buds"
        ),
        "YUEL" => array(
            "young leaves",
            "breaking leaf buds"
        ),
        "LATR2" => array(
            "breaking leaf buds"
        )
    );
    
    $usda = $plant->getUSDASymbol();
    $phenophase_name = strtolower($phenophase_name);
    
    if(array_key_exists($usda, $ignore_matrix) && in_array($phenophase_name,$ignore_matrix[$usda])){
        return true;
    }
    
    return false;
    
    
}


function findNPNSpeciesID($usda_symbol, $recurse=false){
    global $mysql;
    global $log;
    
    try{
        $query = "SELECT * FROM usanpn2.Species WHERE USDA_Symbol = '" . $usda_symbol . "'";
        $results = $mysql->getResults($query);
        $species_id = null;

        while($row = $results->fetch()){
            $species_id = $row['Species_ID'];
        }
        
        if(!$species_id && !$recurse){
            
            //USDA Symbols between systems: NEON => NPN
            $usda_map = array(
                "COCOC" => "COCO6",
                "PSMEM" => "PSME",
                "EUCA26" => "EUGR5",
                "ARBE7" => "ARST5",
                "ACRUR" => "ACRU",
                "CARUD" => "CARU3",
                "GEROT" => "GERO2",
                "QUMO4" => "QUPR2",
                "CAHAF" => "OEHA3",
                "BEGL/BENA" => "BENA"
            );
            
            if(array_key_exists($usda_symbol, $usda_map)){
                $species_id = findNPNSpeciesID($usda_map[$usda_symbol],true);
            } 
        }
        
        if(!$species_id && !$recurse){
            throw new Exception("No species ID present.");
        }
        
        /*
         * In this one case species 444 has the same USDA symbol as species 12
         * because it's a cloned variety of the same plant but for phenophase/
         * intensity measure purposes as per NEON's data it should be considered
         * the species_id = 12 in all cases.
         */
        if($species_id == 444){
            $species_id = 12;
        }
        
    }catch(Exception $ex){
        $log->write("Error: Could not find a species from NEON data: " . $usda_symbol);
    }
    
    return $species_id;
    
}


function stationExists($name){
    global $mysql;
    
    $query = "SELECT * FROM usanpn2.Station WHERE Station_Name = '" . $name . "'";
    $results = $mysql->getResults($query);
    $station = null;
    while($row = $results->fetch()){
        $station = new Station();
        $station->npn_id = $row['Station_ID'];
        $station->setLatitude($row['Latitude']);
        $station->setLongitude($row['Longitude']);
        $station->setElevation($row['Elevation_m']);        
        $station->deriveSpeciesSeqNum($mysql, $log);
        $station->setLoadKey($row['Load_Key']);
    }
    
    return $station;
    
}

function plantExists($name){
    global $mysql;
    
    $query = "SELECT Station_Species_Individual.*, Species.USDA_Symbol, Species.Species_ID "
            . "FROM usanpn2.Station_Species_Individual "
            . "LEFT JOIN usanpn2.Species "
            . "ON Species.Species_ID = Station_Species_Individual.Species_ID "
            . "WHERE Individual_UserStr = '" . $name . "'";
    
    $results = $mysql->getResults($query);
    $plant = null;
    while($row = $results->fetch()){
        $plant = new Individual();
        $plant->setNPNID($row['Individual_ID']);
        $plant->setStationID($row['Station_ID']);
        $plant->setSpeciesID($row['Species_ID']);
        $plant->setActive($row['Active']);
        $plant->setSeqNum($row['Seq_Num']);
        $plant->setComment($row['Comment']);
        $plant->setCreateDate($row['Create_Date']);
        
        $plant->setName($name);
        $plant->setUSDASymbol($row['USDA_Symbol']);
    }
    
    return $plant;
    
}



class Individual{
    
    public $neonID;
    public $npn_id;
    public $name;
    public $species_id;
    public $usdaSymbol;
    public $stationID;
    public $active;
    public $seqNum;
    public $createDate;
    public $patch;
    public $patchSize;
    public $patchSizeUnitsID;
    public $comment;
    
    public function __construct(){
        $this->active = 1;
        $this->patch = 0;
    }
    
    function getUSDASymbol() {
        return $this->usdaSymbol;
    }

    function setUSDASymbol($usdaSymbol) {
        $this->usdaSymbol = $usdaSymbol;
        $this->getSpeciesFromUSDA();
    }
    
    public function getSpeciesFromUSDA(){
        global $mysql;
        
        $query = "SELECT Species_ID FROM usanpn2.Species WHERE USDA_Symbol = '" . $this->usdaSymbol . "'";
        $results = $mysql->getResults($query);
        while($row = $results->fetch()){
            $this->setSpeciesID($row['Species_ID']);            
        }
    }

    function getStationID() {
        return $this->stationID;
    }

    function getActive() {
        return $this->active;
    }

    function getSeqNum() {
        return $this->seqNum;
    }

    function getCreateDate() {
        return $this->createDate;
    }

    function getPatch() {
        return $this->patch;
    }

    function getPatchSize() {
        return $this->patchSize;
    }

    function getPatchSizeUnitsID() {
        return $this->patchSizeUnitsID;
    }

    function setStationID($stationID) {
        $this->stationID = $stationID;
    }

    function setActive($active) {
        $this->active = $active;
    }

    function setSeqNum($seqNum) {
        $this->seqNum = $seqNum;
    }

    function setCreateDate($createDate) {
        $this->createDate = $createDate;
    }

    function setPatch($patch) {
        $this->patch = $patch;
    }

    function setPatchSize($patchSize) {
        $this->patchSize = $patchSize;
    }

    function setPatchSizeUnitsID($patchSizeUnitsID) {
        $this->patchSizeUnitsID = $patchSizeUnitsID;
    }

    
            
    function getNeonID() {
        return $this->neonID;
    }

    function getNPNID() {
        return $this->npn_id;
    }

    function getName() {
        return $this->name;
    }

    function getSpeciesID() {
        return $this->species_id;
    }

    function setNeonID($neonID) {
        $this->neonID = $neonID;
    }

    function setNPNID($npn_id) {
        $this->npn_id = $npn_id;
    }

    function setName($name) {
        $this->name = $name;
    }

    function setSpeciesID($species_id) {
        $this->species_id = $species_id;
    }
    
    function getComment() {
        return $this->comment;
    }

    function setComment($comment) {
        $this->comment = $comment;
    }

        
    function insert(&$mysql, &$log){
        
        $status = false;
        
        
        try{
            $query = "INSERT INTO usanpn2.Station_Species_Individual (Station_ID, Species_ID, Individual_UserStr, Active, Seq_Num, `Comment`, Create_Date)" .
                    " VALUES (" .
                    $this->getStationID() . ", " .
                    $this->getSpeciesID() . ", " .
                    "'" . $this->getName() . "', " .
                    $this->getActive() . ", " .
                    $this->getSeqNum() . ", " .
                    "'" . $this->getComment() . "', " .
                    "'" . $this->getCreateDate()->format("Y-m-d") . "')";

            $mysql->runQuery($query);
            $status = true;
            $this->setNPNID($mysql->getId());
        }catch(Exception $ex){
            $log->write("There was a problem creating an individual.");
            $log->write(print_r($this, true));
            $log->write(print_r($ex, true));
        }
        
        return $status;
                
    }
    
    function updatePatchStatus(&$mysql, &$log){
        $status = false;
        
        try{
            $query = "UPDATE usanpn2.Station_Species_Individual " .
                    " SET Patch = " . $this->patch . "," .
                    " Patch_Size = " . (($this->patchSize && $this->patchSize != "NA") ? $this->patchSize : "null") . ", " .
                    " Patch_Size_Units_ID = "  . (($this->patchSizeUnitsID && $this->patchSize && $this->patchSize != "NA") ? $this->patchSizeUnitsID : "null") . " " .
                    " WHERE Individual_ID = " . $this->getNPNID();

            $mysql->runQuery($query);
            $status = true;
        }catch(Exception $ex){
            $log->write("There was a problem creating an individual.");
            $log->write(print_r($this, true));
            $log->write(print_r($ex, true));
        }
        
        return $status;        
    }


}


class Station{
    public $neonID;
    public $npn_id;
    public $name;
    private $latitude;
    private $longitude;
    private $elevation;
    
    private $observerID;
    private $latLongDatum;
    private $comment;
    
    private $country;
    private $elevationSource;
    private $latLongSource;
    private $active;
    
    private $elevationUser;
    private $elevationCalc;
    private $elevationCalcSource;
    
    private $latitudeUser;
    private $longitudeUser;
    
    private $loadKey;
    
    private $public;
    
    private $gmt_difference;
    
    private $shortLatitude;
    private $shortLongitude;
    
    private $createDate;
    
    private $speciesSeqNum;
    
    
    
    public function __construct(){
        $this->latLongDatum = "WGS84";
        $this->country = "USA";
        $this->elevationSource = "NEON";
        $this->latLongSource = "NEON";
        $this->active = 1;
        $this->elevationCalcSource = "NEON";
        $this->public = 0;
        $this->observer_id = -1;
        
        $this->createDate = new DateTime();
    }
    
    function getSpeciesSeqNum() {
        return $this->speciesSeqNum;
    }

    function setSpeciesSeqNum($speciesSeqNum) {
        $this->speciesSeqNum = $speciesSeqNum;
    }
    
    function deriveSpeciesSeqNum(&$mysql, &$log){
        
        
        
        
        try{
            $query = "SELECT MAX(Seq_Num) `seq` FROM usanpn2.Station_Species_Individual WHERE Station_ID = " . $this->getNpn_id();
            $results = $mysql->getResults($query);
            $seq_num = null;
            while($row = $results->fetch()){
                $seq_num = intval($row['seq']) + 1;
            }
        }catch(Exception $ex){
            $log->write("Error finding species seq num: " . $this->getNpn_id() . ' ' . $this->getNeonID());
            $seq_num = 1;
        }
        
        $this->setSpeciesSeqNum($seq_num);
    }

        
    function getLatitude() {
        return $this->latitude;
    }

    function getLongitude() {
        return $this->longitude;
    }

    function getElevation() {
        return $this->elevation;
    }
    
    function getGMTDifference() {
        return $this->gmt_difference;
    }

    function setGMTDifference($gmt_difference) {
        $this->gmt_difference = $gmt_difference;
    }
    
    
    function getNeonID() {
        return $this->neonID;
    }

    //IS THIS THE BEST HANDLE FOR THIS CASE??
    function getNpn_id() {
        return ($this->npn_id) ? $this->npn_id : -1; 
    }

    function getName() {
        return $this->name;
    }

    function getObserverID() {
        return $this->observerID;
    }

    function getLatLongDatum() {
        return $this->latLongDatum;
    }

    function getComment() {
        return $this->comment;
    }

    function getCountry() {
        return $this->country;
    }

    function getElevationSource() {
        return $this->elevationSource;
    }

    function getLatLongSource() {
        return $this->latLongSource;
    }

    function getActive() {
        return $this->active;
    }

    function getElevationUser() {
        return $this->elevationUser;
    }

    function getElevationCalc() {
        return $this->elevationCalc;
    }

    function getElevationCalcSource() {
        return $this->elevationCalcSource;
    }

    function getLatitudeUser() {
        return $this->latitudeUser;
    }

    function getLongitudeUser() {
        return $this->longitudeUser;
    }

    function getLoadKey() {
        return $this->loadKey;
    }

    function getPublic() {
        return $this->public;
    }

    function getShortLatitude() {
        return $this->shortLatitude;
    }

    function getShortLongitude() {
        return $this->shortLongitude;
    }

    function setNeonID($neonID) {
        $this->neonID = $neonID;
    }

    function setNpn_id($npn_id) {
        $this->npn_id = $npn_id;
    }

    function setName($name) {
        $this->name = $name;
    }

    function setObserverID($observerID) {
        $this->observerID = $observerID;
    }

    function setLatLongDatum($latLongDatum) {
        $this->latLongDatum = $latLongDatum;
    }

    function setComment($comment) {
        $this->comment = $comment;
    }

    function setCountry($country) {
        $this->country = $country;
    }

    function setElevationSource($elevationSource) {
        $this->elevationSource = $elevationSource;
    }

    function setLatLongSource($latLongSource) {
        $this->latLongSource = $latLongSource;
    }

    function setActive($active) {
        $this->active = $active;
    }

    function setElevationUser($elevationUser) {
        $this->elevationUser = $elevationUser;
    }

    function setElevationCalc($elevationCalc) {
        $this->elevationCalc = $elevationCalc;
    }

    function setElevationCalcSource($elevationCalcSource) {
        $this->elevationCalcSource = $elevationCalcSource;
    }

    function setLatitudeUser($latitudeUser) {
        $this->latitudeUser = $latitudeUser;
    }

    function setLongitudeUser($longitudeUser) {
        $this->longitudeUser = $longitudeUser;
    }

    function setLoadKey($loadKey) {
        $this->loadKey = $loadKey;
    }

    function setPublic($public) {
        $this->public = $public;
    }

    function setShortLatitude($shortLatitude) {
        $this->shortLatitude = $shortLatitude;
    }

    function setShortLongitude($shortLongitude) {
        $this->shortLongitude = $shortLongitude;
    }
    
    
    function getCreateDate() {
        return $this->createDate;
    }

    function setCreateDate($createDate) {
        $this->createDate = $createDate;
    }

    function setLatitude($latitude) {
        $this->latitude = $latitude;
        $this->latitudeUser = $latitude;
        $this->shortLatitude = round($latitude,3);
        
        if($this->longitude != null && !$this->getGMTDifference()){
            $this->generateGMT();
        }
    }

    function setLongitude($longitude) {
        $this->longitude = $longitude;
        $this->longitudeUser = $longitude;
        $this->shortLongitude = round($longitude,3);
        
        if($this->latitude != null  && !$this->getGMTDifference()){
            $this->generateGMT();
        }
    }

    function setElevation($elevation) {
        $this->elevation = $elevation;
        $this->elevationUser = $elevation;
        $this->elevationCalc = $elevation;
    }
    
    
    function generateGMT(){      
        $gmt = null;

        if(!DEBUG){
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => 'http://api.timezonedb.com/v2.1/get-time-zone?key=RFUSKDDK5QZO&format=json&lat=' . $this->getLatitude() . '&lng=' . $this->getLongitude() . '&by=position'
            ]);

            $result = curl_exec($curl);
            sleep(1);

            $data = json_decode($result);
            $gmt = $data->gmtOffset / 60 / 60;
        }else{
            $gmt = -7;
        }
        
        $this->setGMTDifference($gmt);      
        return $gmt;
    }
    
    function insert(&$mysql, &$log){
        
        $status = false;
        $query = "INSERT INTO usanpn2.Station (Observer_ID, Station_Name, Latitude, Longitude, Lat_Lon_Datum, Elevation_m, Comment, Country," .
                "Elevation_Source, Lat_Lon_Source, Active, Elevation_User_m, Elevation_Calc_m, Elevation_Calc_Source, Latitude_User, Longitude_User," .
                "Load_Key, Create_Date, Public, GMT_Difference, Short_Latitude, Short_Longitude) VALUES (" .
                
                $this->observerID . "," .
                "'" . $this->name . "', " .
                $this->getLatitude() . ", " .
                $this->getLongitude() . ", " .
                "'" . $this->getLatLongDatum() . "', " .
                $this->getElevation() . ", " .
                "'" . $this->getComment() . "', " .
                "'" . $this->getCountry() . "', " .
                "'" . $this->getElevationSource() . "', " .
                "'" . $this->getLatLongSource() . "', " .
                $this->getActive() . ", " .
                $this->getElevationUser() . ", " .
                $this->getElevationCalc() . ", " .
                "'" . $this->getElevationCalcSource() . "', " .
                $this->getLatitudeUser() . ", " .
                $this->getLongitudeUser() . ", " .
                "'" . $this->getLoadKey() . "', " .
                "'" . $this->getCreateDate()->format("Y-m-d") . "', " .
                $this->getPublic() . ", " .
                $this->getGMTDifference() . ", " .
                $this->getShortLatitude() . ", " .
                $this->getShortLongitude() . ")";
        try{
            $mysql->runQuery($query);
            $status = true;
            $this->setNpn_id($mysql->getId());
            $this->setSpeciesSeqNum(1);
        }catch(Exception $ex){
            $log->write("There was a problem inserting station");
            $log->write(print_r($this, true));
        }
        
        return $status;
        
    }


}



