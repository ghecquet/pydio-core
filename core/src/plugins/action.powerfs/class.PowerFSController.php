<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */

defined('AJXP_EXEC') or die('Access not allowed');

/**
 * @package AjaXplorer_Plugins
 * @subpackage Action
 */
class PowerFSController extends AJXP_Plugin
{

    public function performChecks(){
        if(ShareCenter::currentContextIsLinkDownload()) {
            throw new Exception("Disable during link download");
        }
    }

    public function switchAction($action, $httpVars, $fileVars)
    {
        if(!isSet($this->actions[$action])) return;
        $selection = new UserSelection();
        $dir = $httpVars["dir"] OR "";
        $dir = AJXP_Utils::decodeSecureMagic($dir);
        if($dir == "/") $dir = "";
        $selection->initFromHttpVars($httpVars);
        if (!$selection->isEmpty()) {
            //$this->filterUserSelectionToHidden($selection->getFiles());
        }
        $urlBase = "ajxp.fs://". ConfService::getRepository()->getId();
        $mess = ConfService::getMessages();
		
        switch ($action) {
            case "monitor_compression" :

                $percentFile = fsAccessWrapper::getRealFSReference($urlBase.$dir."/.zip_operation_".$httpVars["ope_id"]);
                $percent = 0;
                if (is_file($percentFile)) {
                    $percent = intval(file_get_contents($percentFile));
                }
                if ($percent < 100) {
                    AJXP_XMLWriter::header();
                    AJXP_XMLWriter::triggerBgAction(
                        "monitor_compression",
                        $httpVars,
                        $mess["powerfs.1"]." ($percent%)",
                        true,
                        1);
                    AJXP_XMLWriter::close();
                } else {
                    @unlink($percentFile);
                    AJXP_XMLWriter::header();
                    if ($httpVars["on_end"] == "reload") {
                        AJXP_XMLWriter::triggerBgAction("reload_node", array(), "powerfs.2", true, 2);
                    } else {
                        $archiveName =  $httpVars["archive_name"];
                        $jsCode = "
                            var regex = new RegExp('.*?[&\\?]' + 'minisite_session' + '=(.*?)&.*');
                            val = window.ajxpServerAccessPath.replace(regex, \"\$1\");
                            var minisite_session = ( val == window.ajxpServerAccessPath ? false : val );

                            $('download_form').action = window.ajxpServerAccessPath;
                            $('download_form').secure_token.value = window.Connexion.SECURE_TOKEN;
                            $('download_form').select('input').each(function(input){
                                if(input.name!='secure_token') input.remove();
                            });
                            $('download_form').insert(new Element('input', {type:'hidden', name:'ope_id', value:'".$httpVars["ope_id"]."'}));
                            $('download_form').insert(new Element('input', {type:'hidden', name:'archive_name', value:'".$archiveName."'}));
                            $('download_form').insert(new Element('input', {type:'hidden', name:'get_action', value:'postcompress_download'}));
                            if(minisite_session) $('download_form').insert(new Element('input', {type:'hidden', name:'minisite_session', value:minisite_session}));
                            $('download_form').submit();
                            $('download_form').get_action.value = 'download';
                        ";
                        AJXP_XMLWriter::triggerBgJsAction($jsCode, $mess["powerfs.3"], true);
                        AJXP_XMLWriter::triggerBgAction("reload_node", array(), "powerfs.2", true, 2);
                    }
                    AJXP_XMLWriter::close();
                }

                break;

            case "postcompress_download":
                $archive = AJXP_Utils::getAjxpTmpDir().DIRECTORY_SEPARATOR.$httpVars["ope_id"]."_".AJXP_Utils::sanitize(AJXP_Utils::decodeSecureMagic($httpVars["archive_name"]), AJXP_SANITIZE_FILENAME);
                $fsDriver = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("access");
                if (is_file($archive)) {
                    register_shutdown_function("unlink", $archive);
                    $fsDriver->readFile($archive, "force-download", $httpVars["archive_name"], false, null, true);
                } else {
                    echo("<script>alert('Cannot find archive! Is ZIP correctly installed?');</script>");
                }
                break;

            case "compress" :
            case "precompress" :

                $archiveName = AJXP_Utils::sanitize(AJXP_Utils::decodeSecureMagic($httpVars["archive_name"]), AJXP_SANITIZE_FILENAME);
				
                if (!ConfService::currentContextIsCommandLine() && ConfService::backgroundActionsSupported()) {
                    $opeId = substr(md5(time()),0,10);
                    $httpVars["ope_id"] = $opeId;
                    AJXP_Controller::applyActionInBackground(ConfService::getRepository()->getId(), $action, $httpVars);
                    AJXP_XMLWriter::header();
                    $bgParameters = array(
                        "dir" => SystemTextEncoding::toUTF8($dir),
                        "archive_name"  => SystemTextEncoding::toUTF8($archiveName),
                        "on_end" => (isSet($httpVars["on_end"])?$httpVars["on_end"]:"reload"),
                        "ope_id" => $opeId
                    );
					
                    AJXP_XMLWriter::triggerBgAction(
                        "monitor_compression",
                        $bgParameters,
                        $mess["powerfs.1"]." (0%)",
                        true);
                    AJXP_XMLWriter::close();
                    session_write_close();
                    exit();
                }
				
				$rootDir = fsAccessWrapper::getRealFSReference($urlBase . $dir);
				
				// List all files
				$args = array();
				foreach ($selection->getFiles() as $selectionFile) {
					$baseFile = $selectionFile;
					$args[] = escapeshellarg(substr($selectionFile, strlen($dir)+($dir=="/"?0:1)));
					$selectionFile = fsAccessWrapper::getRealFSReference($urlBase.$selectionFile);
					
					if(trim($baseFile, "/") == ""){
						// ROOT IS SELECTED, FIX IT
						$args = array(escapeshellarg(basename($rootDir)));
						$rootDir = dirname($rootDir);
						break;
					}
				}
				
				$files = "";
				foreach ($args as &$value)
				{
					$valuef = $rootDir."\\".str_replace("\"","",$value);
					if (is_dir($valuef))
						$files = $files . $this->read_dir_recursive($valuef, $rootDir); 
					else if (substr($value, 0, 5) != ".axp.")
						$files = $files . str_replace("\"","",$value) . "\r\n"; 
				}
				$files = substr($files, 0, strlen($files) - 2);
				
				$compressLocally = ($action == "compress" ? true : false);
				$baseDir = "";
				$baseName = $archiveName;
				
				// Setting parameters
				$zipper = $this->_getZipperOptions();
				
				if (!$compressLocally) {
					$baseDir = AJXP_Utils::getAjxpTmpDir();
					$baseName = $httpVars["ope_id"] . "_" . $archiveName;
				}
				
				// Building files
				$outputFilesPath = $this->_buildCompressionFiles(
					$rootDir,
					$baseName,
					$httpVars["ope_id"],
					$files
				);
				
				// Building command
				$command = $this->_buildCompressionCommand(
					$rootDir,
					$baseDir . $baseName,
					$files,
					$zipper,
					$outputFilesPath
				);
				
				// Triggering command
				AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Executing zip command");
				AJXP_Logger::debug(__CLASS__,__FUNCTION__,$command);
				$proc = popen($command, 'r');
		
				// Monitoring
				$this->_buildMonitoring($proc, $zipper, $outputFilesPath);

				// Closing
				AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Closing Proc");
				pclose($proc);

				// Clearing
				$this->_clear($outputFilesPath);
				
                break;
            default:
                break;
        }
    }
	
	/*
	 * Setting ZIP Parameters Options
	 */
	private function _getZipperOptions() {
		
		$zip_type = $this->getFilteredOption("ZIP_TYPE");
		$zip_path = $this->getFilteredOption("ZIP_PATH");

		if($this->getFilteredOption("MAX_ZIP_TIME") != 0)
            $max_zip_time = intval($this->getFilteredOption("MAX_ZIP_TIME"));
        else
            $max_zip_time = 120;
			
		// TODO - TO CHECK
		//if (substr($zip_path, -1) != "\\" || substr($zip_path, -1) != "/") $zip_path = $zip_path . "\\";
			
		$ret = array(
			"type" => $zip_type,
			"path" => $zip_path,
			"max_zip_time" =>  $max_zip_time
		);
		
		return $ret;
	}
	
	/*
	 * Building the different Files needed by the tools
	 */
	private function _buildCompressionFiles($rootDir, $baseName, $ope_id, $files) {
		
		$filesname     = $rootDir ."/.axp.".$baseName.".files.txt";
		$logfile       = $rootDir ."/.axp.".$baseName.".processed.txt";
		$clioutputfile = $rootDir ."/.axp.".$baseName.".cli_output.log";
		$clierrorfile  = $rootDir ."/.axp.".$baseName.".cli_error.log";
		$zipspeedfile  = $rootDir ."/.axp.".$baseName.".avgspeed.txt";
		$percentfile   = $rootDir ."/.zip_operation_".$ope_id;
		
		file_put_contents($filesname, $files);
		
		$ret = array (
			'filesname'     => $filesname,
			'logfile'       => $logfile,
			'clioutputfile' => $clioutputfile,
			'clierrorfile'  => $clierrorfile,
			'zipspeedfile'  => $zipspeedfile,
			'percentfile'   => $percentfile
		);
		
		//AJXP_Logger::debug(__CLASS__,__FUNCTION__,"Files :\r\n" . $ret);
		return $ret;
		
	}
	
	/*
	 * Building the command that is going to be used to restore the file
	 */
	private function _buildCompressionCommand($rootDir, $archiveName, $files, $zipper, $outputFilesPath)
	{
		$zip_type = $zipper["type"];
		$zip_path = $zipper["path"];
		
		$filesname = $outputFilesPath["filesname"];
		
		//Setting file names
		$cmdSeparator = ((PHP_OS == "WIN32" || PHP_OS == "WINNT" || PHP_OS == "Windows")? "&" : ";");
		
		//Changing root dir
		chdir($rootDir);
		AJXP_Logger::debug(__CLASS__,__FUNCTION__, "Current Dir : " . getcwd());

		switch ($zip_type){
			case "zip" :
				$cmd = $zip_path . " -r ".escapeshellarg($archiveName)." ". "\"" . str_replace("\r\n", "\" \"", $files) . "\"";
				break;
			case "winrar" :
				$cmd = $zip_path . " a -afzip " . escapeshellarg($archiveName) . " @" . escapeshellarg($filesname);
				break;
			case "7zip" :
				$cmd = $zip_path . " a -tzip ".escapeshellarg($archiveName)." @" . escapeshellarg($filesname);
				break;
			case "other" :
				$zip_exe = $zip_path;
				$zip_exe = str_replace("%archive%", escapeshellarg($archiveName), $zip_exe);
				$zip_exe = str_replace("%files%", "\"" . implode("\" \"", $args) . "\"" , $zip_exe);
				$zip_exe = str_replace("%listfile%", escapeshellarg($filesname), $zip_exe);
				$zip_exe = str_replace("%outputfile%", escapeshellarg($logfile), $zip_exe);
				
				$cmd = $zip_path . $zip_exe;
				break;
			default:
				throw new AJXP_Exception("Wrong zip tool");
				break;
		}
		
		if (stripos(PHP_OS, "win") === false) {
			$fsDriver = AJXP_PluginsService::getInstance()->getUniqueActivePluginForType("access");
			$c = $fsDriver->getConfigs();
			if (!isSet($c["SHOW_HIDDEN_FILES"]) || $c["SHOW_HIDDEN_FILES"] == false) {
				$cmd .= " -x .\*";
			}
		}
		
		$cmd .= " ".$cmdSeparator." echo ZIP_FINISHED";

		return $cmd;
	}
	
	/*
	 * Building the monitoring system. Looking through the output file to see if we have our exit command
	 */
	private function _buildMonitoring($proc, $zipper, $outputFilesPath) {
		
		$zip_type = $zipper["type"];
		$max_zip_time = $zipper["max_zip_time"];
		
		$percentFile = $outputFilesPath['percentfile'];
		
		//
		$toks = array();
		$handled = array();
		$finishedEchoed = false;
		
		$percent = 0;
		
		//select & configure monitoring process
		switch ($zip_type) {
			case "winrar" :
				$recogpattern = "/%file%/";
				break;
			default :
				$recogpattern = '/(\w+): (%file%) \(([^\(]+)\) \(([^\(]+)\)/';
				break;
		}
		
		// Monitoring progress
		if (is_resource($proc)) {
			while (!feof($proc)) {
				set_time_limit ($max_zip_time);
				$results = fgets($proc, 256);
			
				if (strlen($results) == 0) {
				
				} else {
					$tok = strtok($results, "\n");
			
					while ($tok !== false) {
						$toks[] = $tok;
						if ($tok == "ZIP_FINISHED") {
							$finishedEchoed = true;
						} else {
							$test = preg_match('/(\w+): (.*) \(([^\(]+)\) \(([^\(]+)\)/', $tok, $matches);
							if ($test !== false) {
								$handled[] = $matches[2];
							}
						}
						$tok = strtok("\n");
					}
					
					if($finishedEchoed) $percent = 100;
					else $percent = min( round(count($handled) / count($todo) * 100),  100);
					file_put_contents($percentFile, $percent);
				}
				
				// avoid a busy wait
				if($percent < 100) usleep(1);
			}
			
			if(! $finishedEchoed) throw new AJXP_Exception("Could not zip");
		} else {
			AJXP_Logger::logAction(__CLASS__,__FUNCTION__, "Zip Process could not be started.");
			file_put_contents($percentFile, 100);
			
			throw new AJXP_Exception("Could not start zip process");
		}
		
		file_put_contents($percentFile, 100);
	}
	
	/*
	 * Clear the files and release file handles if necessary
	 */
	private function _clear($outputFilePaths) {
		$filesname = $outputFilePaths['filesname'];
		$logfile = $outputFilePaths['logfile'];
		$clioutputfile = $outputFilePaths['clioutputfile'];
		$clierrorfile = $outputFilePaths['clierrorfile'];
		$zipspeedfile = $outputFilePaths['zipspeedfile'];
		
		if (file_exists($filesname)) unlink($filesname);
		if (file_exists($logfile)) unlink($logfile);
		if (file_exists($clioutputfile)) unlink($clioutputfile);
		if (file_exists($clierrorfile)) {
			$fcontent = file_get_contents($clierrorfile);
			unlink($clierrorfile);
			if (strlen($fcontent) > 2) AJXP_Logger::logAction(__CLASS__,__FUNCTION__,"Command Line Error " . $fcontent);
		}
		if (file_exists($zipspeedfile)) unlink($zipspeedfile);
	}
	
	// Helper function to retrieve file names
	public function read_dir_recursive($dir, $rootdir) 
	{ 
	    $handle =  opendir($dir);
		$filesr = "";

	    while ($arg = readdir($handle)) 
	    { 
			$val = $dir."\\".$arg;
	        if ($arg != "." && $arg != "..") 
	        { 
	            if (is_dir($val))
	                $filesr = $filesr . $this->read_dir_recursive($val, $rootdir); 
	            else if (strpos($val, ".axp.") === false)
	                $filesr = $filesr . substr($val, strlen($rootdir) + 1) . "\r\n"; 
	        }
	    }
		
	    closedir($handle);
		
		return $filesr;
	} 
}
