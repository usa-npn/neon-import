<?php

/**
 * Parameters for connecting to the database.
 */


/**
 * Quick class for handling the database connection and queries. 
 * This is very much a bare bones class.
 */
class Database{
    
    var $handle;
    var $dsn;
    var $pdo;
    var $output_file;       
    
    
    public function __construct($engine, $host, $user, $pw, $db , $port, &$output_file){
        
        $this->dsn = $engine . ':dbname=' . $db . ';host=' . $host . ';port=' . $port;
        $this->pdo = new PDO($this->dsn, $user, $pw);
        $this->output_file = $output_file;     
        
    }
    
    
    public function runQuery($query){
        try{
            $this->output_file->write($query);
            $result = $this->pdo->exec($query);

            return $result;
        }catch(Exception $ex){
            $this->output_file("Exception occurred with query: " . $query);
            $this->output_file->write(var_export($ex));
            $this->rollback();            
            
            return false;
        }       
    }
    
    public function getResults($qry){
//        $this->output_file->write($qry);
        $query = $this->pdo->prepare($qry);
        $query->execute();
        
        return $query;
        
        
    }
    
    public function getId(){
        return $this->pdo->lastInsertId();
    }
    
    public function close(){
        //$this->output_file->close();
    }
    
    public function beginTransaction(){
        $this->pdo->beginTransaction();
    }
    
    public function rollback(){
        $this->pdo->rollBack();
    }
    
    public function commit(){
        $this->pdo->commit();
    }
    
    
    
}

?>
