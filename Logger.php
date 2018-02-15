<?php


///This class, Logger, creates a directory based on the year, month, and date, and stores a text file containing log messages.  It is also creates a separate log, called LoggerLog.txt
//that records which files are calling this class.  Logger.php is called by any file where logging activity is needed.
//
//Programmer:  Craig Millis 11/5/15
//
//The files that use this class are recorded in a log within the same directory that contains this file.  The files that use this may include:
//  /htdocs/common/db/webSyncRemote/WebSyncManager.php
//
//Files using this class should pass these parameters in an array:
//  logDir            required  string    the directory in which the log will be created, e.g. '/pix/anro/edi/dat_connexion/log/'
//  callingFile       optional  string    this will be overwrittent by the result of a backtrace, if a value is returned by the backtrace
//  addSubDir         optional  boolean   FALSE will result in a log directory with of year, month, date; TRUE will add an incremented subdirectory, e.g. log/2015/11/05/01


class Logger{
  public $requiredParams            = array('logDir');
  public $pruneLoggerLogAtFileSize  = 10; //maximum filesize in megabytes of the LoggerLog.txt file
  public $pruneLogAtFileSize        = 100; //maximum filesize in megabytes of the passed-in log file
  public $pruneLogAfterXxDays       = 30;  //maximum age in days of the passed-in log file
  public $logDetailSetting          = 5;    //this is overridden if a value is passed in params
  public $debug                     = FALSE;//this is overridden if a value is passed in params 

  public function __construct($params){
    //error_reporting(0);
    $this->loggerLogPath  = __DIR__."/LoggerLog.txt";
    $this->orphanLogDir   = __DIR__."/orphanLog/";
    $this->prepareLogger();
    $this->setParams($params);
    $this->createLogDir();
    $this->createLogFile();
    $this->insertLog("Starting log entry called by ".$this->callingFile);
    $this->insertLog("User: ".getenv('USER'));
    $this->prune();
  }

  //Prep for a log
  private function prepareLogger(){
    register_shutdown_function(array($this, "shutdown"));
    $this->oldMask            = umask(0);
    $this->defaultPerms       = 0777;
    $this->defaultPermsDec    = decoct($this->defaultPerms);
    $this->dateObj            = $this->createTimestamp();
    $this->dateTime           = date_format($this->dateObj, "mdHisu");
    $this->dateTimeLastLog    = $this->dateTime;
    $this->logName            = $this->dateTime.".txt";
    $this->log                = $this->dateTime;
  }

  //This function reads an array of parameters that were passed to the constructor
  private function setParams($params){
    if(isset($params)){
      if(is_array($params)){
        foreach($params as $key=>$param){
          $this->$key = $param;
        }
      }
    }
    $this->setCallingAndInitFiles();
    return $this->checkForRequiredParams();
  }

  private function checkForRequiredParams(){
    $paramsOk = TRUE;
    $msg = " Calling File: ".$this->callingFile;
    $msg .= " \nInitiating File: ".$this->initiatingFile;
    
    foreach($this->requiredParams as $requiredParam){
      if(!isset($this->$requiredParam)){
        $msg .= " \nMissing required parameter: ".$requiredParam;
        $paramsOk = FALSE;
      }else{
        $msg .= " \nRequired parameter, ".$requiredParam.": ".$this->$requiredParam;
      }
    }
    
    //If a required parameter is missing then write to the Logger Log and exit
    if($paramsOk == FALSE){ 
      $this->whoIsCallingLoggerLog($msg);
      return FALSE;
    }

    //All of the required parameters are present so insert a message in Logger Log and continue
    $this->whoIsCallingLoggerLog($msg);
    return TRUE;
  }

  //Base on backtrace info, this sets the file that has called this class and the file that initiated the process
  private function setCallingAndInitFiles(){
    $traces     = debug_backtrace();
    $trace_end  = end($traces);

    //Loop through the trace to find the maximum record with this filename
    foreach($traces as $key=>$trace){
      if($trace['file'] == __FILE__){  
        $maxRecord = $key;
      }
    } 

    if(isset($maxRecord)){
      $maxRecord ++;
      $this->callingFile = $traces[$maxRecord]['file'];
    }
    $this->initiatingFile = $trace_end['file'];
  }

  private function createTimestamp(){
    $t = microtime(true);
    $micro = sprintf("%06d",($t - floor($t)) * 1000000);
    $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
    return $d;
  }

  //This function stores which file is calling this class
  private function whoIsCallingLoggerLog($msg){
    $this->pruneLoggerLog(); 
    $log  = "\r\n".$this->dateTime . " " . $msg . "\r\n";
    file_put_contents($this->loggerLogPath, $log, FILE_APPEND);
  }

  ///This function deletes the LoggerLog when it exceeds a certain filesize
  private function pruneLoggerLog(){
    $path = $this->loggerLogPath;
    $pruneAtFileSizeBytes = $this->pruneLoggerLogAtFileSize * 1000000;
    if(file_exists($path)){
      $currentFileSize = filesize($path);
      if(filesize($path) > $pruneAtFileSizeBytes){
        unlink($path);
        $msg = "\r\n" . $this->dateTime . " Deleted log file because its size, " . ($currentFileSize / 1000000) . "Mb, exceeded the limit, " . $pruneAtFileSize;
        file_put_contents($path, $msg);//Craig 012317 removed FILE_APPEND
      }
    }
  }

  private function getPruneLogAfterXxDays(){
    $result = $this->pruneLogAfterXxDays;
    $config = __DIR__.'/configPrune.txt';
    if(!file_exists($config)){
      return $result;
    }  
    $file = file_get_contents($config);

    if($file === FALSE){
      return $result;
    }
    $obj = json_decode($file);
    if(isset($this->passedLogDir)){
      if(isset($obj->{$this->passedLogDir})){
        $result = $obj->{$this->passedLogDir};
        $this->log .= "\nPrune files after ".$result." days for logs written to ".$this->passedLogDir." per $config";
      }
    }
    return $result;
  }

  private function prune(){
    $yearArray    = array('2015','2016','2017','2018','2019', '2020', '2021');
    $logDirArray  = explode("/", $this->logDir);
    $pruneDays    = $this->getPruneLogAfterXxDays();

    //Find year directory
    $n=0;
    foreach(array_reverse($logDirArray, TRUE) as $key=>$dir){
      $n++;
      if(in_array($dir, $yearArray) AND $n > 2){
        $yearKey = $key;
        break;      
      }
    }

    //Ensure that year directory is found.  Proceeding without finding the year directory could result in deleting
    if(!isset($yearKey)){
      $this->log .= "\nCannot prune old files because the year directory is missing in ".$this->logDir;
      return;
    }

    $yearParentDirArray = array_slice($logDirArray, 0, $yearKey);
    $yearParentDir      = implode("/", $yearParentDirArray);
    $now = time();
    $msg = '';

    $this->log   .= "\nPruning files in ".$yearParentDir;

    //Drill down to day directories and check if they're older than allowed
    foreach(glob($yearParentDir.'/*', GLOB_ONLYDIR) as $yearDir){
      $yearArr = explode("/", $yearDir);
      $year = end($yearArr);
      foreach(glob($yearDir.'/*', GLOB_ONLYDIR) as $monthDir){
        $monthArr = explode("/", $monthDir);
        $month = end($monthArr);
        foreach(glob($monthDir.'/*', GLOB_ONLYDIR) as $dayDir){
          $dayArr = explode("/", $dayDir);
          $day = end($dayArr);
          $dir_date = strtotime($year . "-" . $month . "-" . $day);
          $datediff = floor( ($now - $dir_date) / (60*60*24) );
          if($datediff > $pruneDays){
            $msg .= "\n$dayDir is ".$datediff." days old; greater than ".$pruneDays." days...deleting<br>";
            $msg .= $this->rrmdir($dayDir);
          }  
        }
        //Delete the month directory if empty
        if(count(glob($monthDir.'/*', GLOB_ONLYDIR)) == 0){
          $msg .= $this->rrmdir($monthDir);
        }
      }
      //Delete the year directory if empty
      if(count(glob($yearDir.'/*', GLOB_ONLYDIR)) == 0){
        $msg .= $this->rrmdir($yearDir);
      }
    }
    
    if($msg !== ''){
      $currentTime = date_format($this->createTimestamp(), "mdHisu");
      file_put_contents($yearParentDir.'/prune.txt', "\n".$currentTime.$msg);
    }
  }

  //CHANGE THIS FUNCTION AT YOUR OWN RISK!!! YOU COULD DELETE ALL OF YOUR FILES...DANGER DANGER!!
  private function rrmdir($dir) {
    $msg = ''; 
    foreach(glob($dir . '/*') as $file) { 
      if(is_dir($file)){
        $this->rrmdir($file);
      }else{
        if(unlink($file) == TRUE){
          $msg .= "\nSuccess: deleted file ".$file;
        }else{
          $msg .= "\nFailure: did not delete ".$file;
        }
      }
    } 
    if(rmdir($dir) == TRUE){
      $msg .= "\nSuccess: deleted directory ".$dir;
    }else{
      $msg .= "\nFailure: did not delete ".$dir;
    }
    return $msg; 
  }

  public function insertLog($msg){
    $currentTime = date_format($this->createTimestamp(), "mdHisu");
    $elapsedTime = $currentTime - $this->dateTimeLastLog;
    $this->dateTimeLastLog = $currentTime;
    $this->log .= "\n".$elapsedTime."\t\t".$msg;

    if(strlen($this->log) > 10000){
      if(empty($this->noLog)){
        if(file_put_contents($this->logFile, $this->log, FILE_APPEND) !== FALSE){
          chmod($this->logFile, $this->defaultPerms);
          unset($this->log);
        }
      }
    }
  }

  public function insertLogNow($msg){
    $currentTime = date_format($this->createTimestamp(), "mdHisu");
    $elapsedTime = $currentTime - $this->dateTimeLastLog;
    $this->dateTimeLastLog = $currentTime;
    $this->log .= "\n".$elapsedTime."\t\t".$msg;

    if(file_put_contents($this->logFile, $this->log, FILE_APPEND) !== FALSE){
      chmod($this->logFile, $this->defaultPerms);
      unset($this->log);
    }
  }

  //Insert a log, overwriting the preceding portion of the log
  public function insertLogOverwrite($msg){
    $currentTime = date_format($this->createTimestamp(), "mdHisu");
    $elapsedTime = $currentTime - $this->dateTimeLastLog;
    $this->dateTimeLastLog = $currentTime;
    $this->log = "\n".$elapsedTime."\t\t".$msg;

    if(strlen($this->log) > 10000){
      if(empty($this->noLog)){
        if(file_put_contents($this->logFile, $this->log, FILE_APPEND) !== FALSE){
          chmod($this->logFile, $this->defaultPerms);
          unset($this->log);
        }
      }
    }
  }
  
  private function createLogDir(){
    $orphanLogDir = $this->orphanLogDir;
    $logDirOk     = TRUE;

    //Check if the logDir parameter was passed to this class and that the parent directory exists /pix/[qual]/
    if(!empty($this->logDir)){
      $this->log .= "\nLog directory parameter was passed to ".__FILE__.": ".$this->logDir;
      $this->passedLogDir = $this->logDir;

      if(substr($this->logDir, -1) !== "/"){
        $this->logDir .= "/";
        $this->log .= "\nAdding trailing slash to log directory: ".$this->logDir;
      }
      
      $logDirExploded = explode("/", $this->logDir);
      
      if($logDirExploded !== FALSE){
        $parentDir = implode("/", array_slice($logDirExploded, 0, 3));
        if(!file_exists($parentDir)){
          $this->log .= "\nParent directory does not exist: ".$parentDir;
          $logDirOk = FALSE;
        }else{
          $this->log .= "\nParent directory exists: ".$parentDir;
        }
      }else{
        $this->log .= "\nUnrecognized parent directory.  Should be in format '/pix/anro/...'";
        $logDirOk = FALSE;
      }
    }else{
      $this->log .= "\nLog directory parameter was not passed to ".__FILE__;
      $logDirOk = FALSE;
    }

    if($logDirOk == FALSE){
      $this->log .= "\nUsing orphan log: ".$orphanLogDir;
      $this->logDir = $orphanLogDir;
    }

    //Create the log subdirectory if it doesn't exist, e.g. add 2015/12/17/ to /pix/anro/edi/dat_connexion/
    $date     = $this->dateObj->getTimestamp();
    $dateArr  = array("year"=>"Y", "month"=>"m", "day"=>"d");
    foreach($dateArr as $key=>$value){
      $this->logDir .= date($value, $date)."/";
    }

    //Add an incremented subdirectory if requested in the parameters, e.g. log/2015/12/14/ becomes log/2015/12/14/01/
    if(isset($this->addSubDir)){
      if($this->addSubDir == TRUE){
        $subDir = $this->getIncrementedSubdirectory($this->logDir);
        $this->logDir .= $subDir;
      }
    }
    
    if(!file_exists($this->logDir)){
      $this->log .= "\nLog directory does not exist: ".$this->logDir;
      if(!mkdir($this->logDir, $this->defaultPerms, TRUE)){
        $this->log .= "\nFailed to create log directory: ".$this->logDir;
        if(!isset($this->mkdirAttempt)){
          $this->logDir = $orphanLogDir;
          $this->mkdirAttempt = 1;
          $this->createLogDir();
        }else{
          $msg = "Can't create the orphanLogDir!  Need to chmod 777 /htdocs/common/php/logger/";
          $this->whoIsCallingLoggerLog($msg);
        }
      }else{
        $this->log .= "\nCreated log directory ".$this->logDir." with permission ".$this->defaultPermsDec;
      }
    }
  }

  private function createLogFile(){
    //Write to log - must submit full path to file
    if(isset($this->logDir)){
      $this->logFile = $this->logDir.$this->logName;
    }else{
      $this->logFile = $this->whoIsCallingLoggerLogPath;
      $this->insertLog("Error: a file calling ".__FILE__." is not providing required parameters = array('logDir'=>[logDir])"); 
    }
  }

  private function getIncrementedSubdirectory($path){
    //Get all directories in the $path
    $subDirs  = glob($path."*", GLOB_ONLYDIR);
    $max      = 0;
    
    if($subDirs == FALSE){
      //No subdirectories exist within, say, log/2015/12/04/
      return "1/";
    }
    
    foreach($subDirs as $subDir){      
      $pieces = explode('/', $subDir);
      $lastPiece = end($pieces);
      if(is_numeric($lastPiece)){
        //Need if statement because foreach loop will count in order like this: 1, 10, 2, 3, etc
        if($lastPiece > $max){
          $max = $lastPiece;
        }
      }
    }

    $result = $max + 1 . "/";

    return $result;
  }

  public function preLog($msg = '', $logDetail = 1){
    if($this->debug == TRUE OR $logDetail <= $this->logDetailSetting){
      if(is_string($msg)){
        $this->insertLog($msg);
      }else{
        $this->insertLog(print_r($msg, TRUE));
      }
    }
  }

  public function preLogOverwrite($msg = '', $logDetail = 1){
    if($this->debug == TRUE OR $logDetail <= $this->logDetailSetting){
      if(is_string($msg)){
        $this->insertLogOverwrite($msg);
      }else{
        $this->insertLogOverwrite(print_r($msg, TRUE));
      }
    }
  }

  function makeReadableTime($elapsedTime){
    $elapsedTime = str_pad($elapsedTime, 14, '0', STR_PAD_LEFT);
    $vars = array(
      'days'  => -14,
      'hrs'   => -12,
      'mins'  => -10,
      'secs'  => -8
    );
    $str = '';
    foreach($vars as $key=>$value){
      $$key = substr($elapsedTime, $value, 2);
      if(($key == 'secs' || $key == 'mins') AND $$key > 60){
        $$key = $$key - 40;
      }
      $str .= ' '.$$key.$key;
    }
    return trim($str);
  }

  function shutdown(){ 
    if(error_get_last() !== NULL){ 
      $this->insertLog("Last error: ".print_r(error_get_last(), TRUE));
    }
    $this->insertLog("Closing log entry to ".$this->logFile);
    $currentTime = date_format($this->createTimestamp(), "mdHisu");
    $elapsedTime = $currentTime - $this->dateTime;
    $this->log .= "\n".$elapsedTime."\t\tTotal elapsed time";
    $this->log .= "\n".$this->makeReadableTime($elapsedTime)."\t\tTotal elapsed time";
    $this->log .= "\n".$currentTime;

    if(empty($this->noLog)){
      if(file_put_contents($this->logFile, $this->log, FILE_APPEND) !== FALSE){
        chmod($this->logFile, $this->defaultPerms);
      }
    }
  }
}


?>
