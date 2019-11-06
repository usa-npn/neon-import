<?php

/**
 * This class is used to maintain the handle to the output file.
 * This could just as well be called 'OutputFile' as there is
 * almost nothing special about the Lilac Implementation.
 */


class OutputFile{
    
    
    
    var $handle;
    var $file_name;
    
    
    /**
     * Upon create, takes a file name as input. This is the file
     * that the class will write out to.
     */
    public function __construct($file_name){
        
        $this->file_name = $file_name;
        $this->handle = fopen($file_name, 'w+');
        
    }
    
    /**
     *
     * This takes a line of SQL as input, and writes 
     * it to the file. It will automatically append a semi-colon and 
     * next line.
     */
    public function write($sql){
        
        fwrite($this->handle, $sql);
        fwrite($this->handle, ";\n");
    }
    
    
    /**
     * Method for closing the file handle.
     */
    public function close(){
        fclose($this->handle);
    }
    
    public function logError($msg, $date="n/a", $individual_name="n/a", $species="n/a", $growth_form="n/a", $phenophase="n/a"){
        $this->write("\"Error:" . $msg . "\"," . $date . "," . $individual_name . "," . $species . "," . $growth_form . "," . $phenophase);        
    }    
    
}
?>
