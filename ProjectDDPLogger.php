<?php
/**
 * Project DDP Loggger
 * 
 * Enables project-specific DDP config, and facilitates DDP into a project using 
 * another REDCap project as the DDP source
 * 
 * Testing
 * curl -d "user=luke1&project_id=167&redcap_url=" "https://localhost/redcap/api/?type=module&prefix=project_ddp&page=project_ddp&pid=167&service=metadata"
 */

namespace MCRI\ProjectDDP;

/**
 * ProjectDDPLogger
 *
 * @author luke.stevens
 */
class ProjectDDPLogger
{  
        protected $enabled = false;
        protected $loggingFile = false;
        
        public function __construct(bool $enabled) {
                $this->enabled = $enabled;
                $this->loggingFile = dirname(__FILE__).DIRECTORY_SEPARATOR.'project_ddp.log';
        }
        
        public function log($logtext) {
                if ($this->enabled) { 
                        file_put_contents($this->loggingFile, NOW.' '.$logtext.PHP_EOL, FILE_APPEND);
                }
        }
}
