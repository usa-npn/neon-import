<?php
print "Running neon purge script\n";

require_once('database.php');

$params = parse_ini_file(__DIR__ . '/config.ini');
$mysql = null;

try{
    $mysql = new Database("mysql",
            $params['mysql_host'], 
            $params['mysql_user'],
            $params['mysql_pw'],
            $params['mysql_db'],
            $params['mysql_port'], 
            $mysql_log
            );
    
    
}catch(PDOException $ex){
    $error_log->logError("There was an error establishing database connections. Terminating job." . print_r($ex, true));
    exitProgram();
    die();
}


try{

    $observation_ids = array();
    $individual_ids = array();
    $observer_ids = array();
    $submission_ids = array();
    $session_ids = array();
    $observation_group_ids = array();
    $station_ids = array();


    $query = "SELECT Observation_ID FROM usanpn2.Dataset_Observation WHERE Dataset_ID = 16;";
    $results = $mysql->getResults($query);
    
    while($row = $results->fetch()) { //loop dataset_observation
        $observation_id = $row['Observation_ID'];
        $observation_ids[] = $observation_id;

        $query = "SELECT Individual_ID, Observer_ID, Submission_ID, Observation_Group_ID FROM usanpn2.Observation WHERE Observation_ID = " . $observation_id . ";";
        $results = $mysql->getResults($query);
        while($row = $results->fetch()) { //loop observation
            $individual_id = $row['Individual_ID'];
            $individual_ids[] = $individual_id;
            $observer_id = $row['Observer_ID'];
            $observer_ids[] = $observer_id;
            $submission_id = $row['Submission_ID'];
            $submission_ids[] = $submission_id;
            $observation_group_id = $row['Observation_Group_ID'];
            $observation_group_ids[] = $observation_group_id;

            $query = "SELECT Station_ID FROM usanpn2.Station_Species_Individual WHERE Individual_ID = " . $individual_id . ";";
            $results = $mysql->getResults($query);
            while($row = $results->fetch()) { //get station_ids
                $station_id = $row['Station_ID'];
                $station_ids[] = $station_id;

            }

            $query = "SELECT Session_ID FROM usanpn2.Submission WHERE Submission_ID = " . $submission_id . ";";
            $results = $mysql->getResults($query);
            while($row = $results->fetch()) { //get session_ids
                $session_id = $row['Session_ID'];
                $session_ids[] = $session_id;

            }

        }


    }
    echo "Observation IDs: ";
    echo implode( ',', $observation_ids ) . "\n";

    echo "Individual IDs: ";
    echo implode( ',', $individual_ids ) . "\n";

    echo "Observer IDs: ";
    echo implode( ',', $observer_ids ) . "\n";

    echo "Submission IDs: ";
    echo implode( ',', $submission_ids ) . "\n";

    echo "Session IDs: "; 
    echo implode( ',', $session_ids ) . "\n";

    echo "Observation Group IDs: ";
    echo implode( ',', $observation_group_ids ) . "\n";

    echo "Station IDs: ";
    echo implode( ',', $station_ids ) . "\n";

    #delete from the following tables
    #Observation, Dataset_Observation
    #Observation_Group
    #Network_Station
    #Station_Species_Individual, Station,
    #Submission, Person

}catch(Exception $ex){
    $error_log->logError("Unexpected error." . print_r($ex, true));    
    $mysql->rollback();    
}finally{
    exitProgram();
}

function exitProgram(){
    global $mysql;
    $mysql->close();
    exit();
}



