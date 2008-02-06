<?php
/**
 * Api to get log messages for a build
 * 
 * @package Xinc.Plugin
 * @author Arno Schneider
 * @version 2.0
 * @copyright 2007 Arno Schneider, Barcelona
 * @license  http://www.gnu.org/copyleft/lgpl.html GNU/LGPL, see license.php
 *    This file is part of Xinc.
 *    Xinc is free software; you can redistribute it and/or modify
 *    it under the terms of the GNU Lesser General Public License as published
 *    by the Free Software Foundation; either version 2.1 of the License, or    
 *    (at your option) any later version.
 *
 *    Xinc is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU Lesser General Public License for more details.
 *
 *    You should have received a copy of the GNU Lesser General Public License
 *    along with Xinc, write to the Free Software
 *    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
require_once 'Xinc/Api/Module/Interface.php';
require_once 'Xinc/Plugin/Repos/Gui/Dashboard/Detail/Extension.php';

class Xinc_Plugin_Repos_Api_LogMessages implements Xinc_Api_Module_Interface
{
    /**
     * Enter description here...
     *
     * @var Xinc_Plugin_Interface
     */
    protected $_plugin;
    
    /**
     *
     * @param Xinc_Plugin_Interface $plugin
     */
    public function __construct(Xinc_Plugin_Interface &$plugin)
    {
        $this->_plugin = $plugin;
        
    }
    
    /**
     *
     * @return string
     */
    public function getName()
    {
        return 'logmessages';
    }
    /**
     *
     * @return array
     */
    public function getMethods()
    {
        return array('get');
    }
    
    /**
     *
     * @param string $methodName
     * @param array $params
     * @return Xinc_Api_Response_Object
     */
    public function processCall($methodName, $params = array())
    {

        switch ($methodName){
            case 'get':
                return $this->_getLogMessages($params);
                break;
        }
          
       
       
    }
    /**
     * get logmessages and return them
     *
     * @param array $params
     * @return Xinc_Api_Response_Object
     */
    private function _getLogMessages($params)
    {
        
        $project = isset($params['p']) ? $params['p'] : null;
        $buildtime = isset($params['buildtime']) ? $params['buildtime'] : null;
        $start = isset($params['start']) ? (int)$params['start'] : 0;
        $limit = isset($params['limit']) ? (int)$params['limit'] : null;
        $builds = $this->_getLogMessagesArr($project, $buildtime, $start, $limit);
        $responseObject = new Xinc_Api_Response_Object();
        $responseObject->set($builds);
        return $responseObject;
    }
   
   
    /**
     * Get a list of all builds of a project
     *
     * @param string $projectName
     * @param integer $start
     * @param integer $limit
     * @return stdClass
     */
    private function _getLogMessagesArr($projectName, $buildTime, $start, $limit=null)
    {
        $statusDir = Xinc_Gui_Handler::getInstance()->getStatusDir();
        $historyFile = $statusDir . DIRECTORY_SEPARATOR . $projectName . '.history';
        $project = new Xinc_Project();
        $project->setName($projectName);
        $build = Xinc_Build::unserialize($project, $buildTime, $statusDir);
        $timezone = $build->getConfigDirective('timezone');
        if ($timezone !== null) {
            Xinc_Timezone::set($timezone);
        }
        $detailDir = Xinc_Build_History::getBuildDir($project, $buildTime);

        $logXmlFile = $detailDir.DIRECTORY_SEPARATOR.'buildlog.xml';
                        
        if (file_exists($logXmlFile)) {
            /**
             * Add fopen() to the function to just get the loglines
             * that we need.
             * the bigger the logfiles get, the more this gets a 
             * performance problem
             */
            $xmlStr = '';
            $pos = 0;
            $fh = fopen($logXmlFile, 'r');
            $xmlStr = fgets($fh);
            $xmlStr .= fgets($fh);
            while ($pos < $start) {
                $line = fgets($fh);
                $line = trim($line);
                if (empty($line)) continue;
                $pos++;
            }
            if ($limit!=null) {
                $addClosingTag = true;
                for ($i = $pos; $i < $start+$limit; $i++) {
                    $line = fgets($fh);
                    $line = trim($line);
                    if (empty($line)) continue;
                    $xmlStr.= $line;
                    //echo $pos . ' - ' . $start .' - '. $limit . "<br>";
                    $pos++;
                    //if ($pos>=10)die;
                    if (feof($fh)) {
                       $addClosingTag = false;
                       break;
                    }
                }
                if ($addClosingTag) {
                   $xmlStr .='</build>';
                }
            } else {
                while (!feof($fh)) {
                    $line = fgets($fh);
                    $line = trim($line);
                    if (empty($line)) continue;
                    $xmlStr.= $line;
                    $pos++;
                }
            }

            while (!feof($fh)) {
                $line = fgets($fh);
                $line = trim($line);
                if (empty($line)) continue;
                $xmlStr.= $line;
                $pos++;
            }
            fclose($fh);
            $logXml = new SimpleXMLElement($xmlStr);
            
        } else {
            $logXml = new SimpleXmlElement('<log/>');
        }
        $totalCount = $pos - 1; //count($logXml->children());
        $i = $totalCount;
        $logmessages = array();
        $id = $totalCount-$start;
        foreach ($logXml->children() as $logEntry) { 
           
            
            $attributes = $logEntry->attributes();
            $logmessages[] = array( 'id'=>$id--, 
                     'date'=> (string)$attributes->timestamp,
                     'stringdate'=> date('Y-m-d H:i:s', (int)$attributes->timestamp),
                     'timezone' => Xinc_Timezone::get(),
                     'priority'=>(string)$attributes->priority,
                     'message'=>str_replace("\n", '\\n', addcslashes($logEntry, '"\'')));
        }
        /**
         * restore to system timezone
         */
        $xincTimezone = Xinc_Gui_Handler::getInstance()->getConfigDirective('timezone');
        if ($xincTimezone !== null) {
            Xinc_Timezone::set($xincTimezone);
        } else {
            Xinc_Timezone::reset();
        }
        //$logmessages = array_slice($logmessages, $start, $limit, false);

        $object = new stdClass();
        $object->totalmessages = $totalCount;
        $object->logmessages = $logmessages;
        //return new Xinc_Build_Iterator($builds);
        return $object;
    }
}