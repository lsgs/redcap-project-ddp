<?php
/**
 * Project DDP
 * 
 * Enables project-specific DDP config, and facilitates DDP into a project using 
 * another REDCap project as the DDP source
 */
namespace MCRI\ProjectDDP;

require_once 'ProjectDDPService.php';
require_once 'ProjectDDPLogger.php';

use DynamicDataPull;
use ExternalModules\AbstractExternalModule;
use JsonSerializable;
use Logging;
use REDCap;
use Session;

class ProjectDDP extends AbstractExternalModule implements JsonSerializable
{
        const GLOBAL_SECRET_SEED = 'hyCCRgX5q_2DlK&ws$&n=TMMljVTS6qFt|*eWM?TOvG8ebzeeT33K$d^UR+?0PWD';
        const CONFIG_VIA = '<img src="%APP_PATH_IMAGES%puzzle_small.png" style="margin:0 5px;"><span class="cc_info">Configure via "Project DDP" External Module.</span>';
        protected $page;
        protected $project_id;
        protected $ddp_encryption_key;
        protected $Proj;
        protected $lang;
        protected $user_rights;
        protected $event_id;
        protected $record;
        protected $repeat_instance;
        protected $sourceType;
        protected $dataSourceName;
        protected $useSource2ndId = false;
        protected $secret;
        protected $global_secret;
        protected $dataUrl;
        protected $metadataUrl;
        protected $userAccessUrl;
        protected $logger = null;

        public function jsonSerialize() { return get_object_vars($this); }
        
        public function __construct() {
                parent::__construct();
                global $Proj, $lang, $user_rights;
                $this->page = PAGE;
                $this->super_user = defined('SUPER_USER') && SUPER_USER;
                $this->lang = &$lang; //nb. $lang is an array which is apparently not an object, so & required to assign by reference
                $this->user_rights = &$user_rights;
                $this->ddp_encryption_key = DynamicDataPull::DDP_ENCRYPTION_KEY;
                $this->global_secret = encrypt(self::GLOBAL_SECRET_SEED, $this->ddp_encryption_key, true);
                
                if (defined('PROJECT_ID') && PROJECT_ID>0) {
                        $this->project_id = PROJECT_ID;
                        $this->Proj = $Proj;
                        $this->sourceType = $this->getProjectSetting('ddp-source-type');
                        $this->logger = new ProjectDDPLogger(('y'==$this->getSystemSetting('logging-enabled-system')) || ('y'==$this->getProjectSetting('logging-enabled-project')));
                        
                        switch ($this->sourceType) {
                            case '1':
                                $this->setSecret();
                                $serviceUrl = $this->getUrl('project_ddp.php', true, true);
                                $this->dataSourceName = $this->getProjectSetting('redcap-project');
                                $this->useSource2ndId = ('2'===$this->getProjectSetting('redcap-project-lookup-field'));
                                $this->dataUrl = $serviceUrl.'&secret='.rawurlencode($this->secret).'&service=data';
                                $this->metadataUrl = $serviceUrl.'&secret='.rawurlencode($this->secret).'&service=metadata';
                                $this->userAccessUrl = $serviceUrl.'&secret='.rawurlencode($this->secret).'&service=user';
                                //$this->log('Create ProjectDDP: '.json_encode($this));
                                break;

                            case '2': // custom
                                $this->dataSourceName = $this->getProjectSetting('external-source-name');
                                $this->dataUrl = $this->getProjectSetting('external-url-data');
                                $this->metadataUrl = $this->getProjectSetting('external-url-metadata');
                                $this->userAccessUrl = $this->getProjectSetting('external-url-user');
                                break;
                            default:
                                $this->Proj->realtime_webservice_enabled = false;
                                break;
                        }
                } else {
                        $this->dataUrl = $this->getUrl('global_ddp.php', true, true).'&secret='.rawurlencode($this->global_secret).'&service=data';
                        $this->metadataUrl = '';
                        $this->userAccessUrl = '';
                        $this->logger = new ProjectDDPLogger(('y'==$this->getSystemSetting('logging-enabled-system')));
                }
        }

        public function getDataSourceName() {
                return $this->dataSourceName;
        }

        public function getProj() {
                return $this->Proj;
        }
        
        public function getSuperUser() {
                return $this->super_user;
        }
        
        public function getUseSource2ndId() {
                return $this->useSource2ndId;
        }
        
        public function getGlobalSecret() {
                return $this->global_secret;
        }
        
        protected function setSecret($secret=null) {
                if (empty($secret) && isset($_COOKIE['PHPSESSID'])) {
                        $ddp = $this->DDP;
                        $this->secret = encrypt($_COOKIE['PHPSESSID'], $this->ddp_encryption_key, true);
                        //$decryptit = decrypt($this->secret, $ddp::DDP_ENCRYPTION_KEY, true);
                } else {
                        $this->secret = $secret;
                }
        }
        
        public function validateSecret($requestSecret, $requestUser) {
                //return true;
                $requestSecret = rawurldecode($requestSecret);
                
                $result = false;
                if ($requestUser==='') {
                        // global_ddp, cron-triggered fetch: validate secret passed in matches module global secret
                        $result = ($this->global_secret===$requestSecret);
                } else {
                        // project_ddp, user-triggered fetch: validate project secret
                        if ($requestSecret!=='' && $requestSecret===$this->getProjectSetting('project-test-secret')) { return true; }
                        
                        // decrypted secret should be an active session of the user in the request post
                        $requestSecret = decrypt($requestSecret, $this->ddp_encryption_key, true);
                        $requestSecret = preg_replace("/[^\w]/", "", $requestSecret);
                        $requestSecret = substr($requestSecret, 0, 32);
                        if (Session::read($requestSecret)) {
                                $result = db_result(db_query("select 1 from redcap_log_view where user='".db_escape($requestUser)."' and session_id='".db_escape($requestSecret)."' limit 1"), 0);
                        }
                }
                return (bool)$result;
        }
        
        /**
         * redcap_module_system_enable
         * Set control Center DDP settings
         * @param string $version
         */
        function redcap_module_system_enable($version) {
                $this->updateGlobalConfig(true);
        }

        /**
         * redcap_module_system_disable
         * Set control Center DDP settings
         * @param string $version
         */
        function redcap_module_system_disable($version) {
                $this->updateGlobalConfig(false);
        }
        
        protected function updateGlobalConfig($enable) {
                if ($enable) {
                        $globalSettings = array(
                            'realtime_webservice_global_enabled' => (int)$enable,
                            'realtime_webservice_source_system_custom_name' => 'Project DDP External Module',
                            'realtime_webservice_url_data' => $this->dataUrl,
                            'realtime_webservice_url_metadata' => $this->metadataUrl,
                            'realtime_webservice_url_user_access' => $this->userAccessUrl
                        );
                } else {
                        $globalSettings = array(
                            'realtime_webservice_global_enabled' => (int)$enable,
                            'realtime_webservice_source_system_custom_name' => '',
                            'realtime_webservice_url_data' => '',
                            'realtime_webservice_url_metadata' => '',
                            'realtime_webservice_url_user_access' => ''
                        );
                }
                foreach ($globalSettings as $this_field=>$this_value) {
        		$sql = "UPDATE redcap_config SET value = '".db_escape($this_value)."' WHERE field_name = '".db_escape($this_field)."'";
                        $q = db_query($sql);

                        // Log changes (if change was made)
                        if ($q && db_affected_rows() > 0) {
                                $sql_all[] = $sql;
                                $changes_log[] = "$this_field = '$this_value'";
                        }
        	}

                // Log any changes in log_event table
                if (count($changes_log) > 0) {
                        Logging::logEvent(implode(";\n",$sql_all),"redcap_config","MANAGE","",implode(",\n",$changes_log),"Modify system configuration");
                }
        }

        /**
         * redcap_module_project_enable
         * Enable project realtime_webservice_enabled
         * @param string $version
         */
        function redcap_module_project_enable($version, $project_id) {
                $this->updateProjectConfig(true, $project_id);
        }

        /**
         * redcap_module_project_disable
         * Disable project realtime_webservice_enabled
         * @param string $version
         */
        function redcap_module_project_disable($version, $project_id) {
                $this->updateProjectConfig(false, $project_id);
        }
        
        protected function updateProjectConfig($enable, $project_id) {
                $enable = (int)$enable;
                $sql = "update redcap_projects set realtime_webservice_enabled = '".db_escape($enable)."', realtime_webservice_type = 'CUSTOM' where project_id = ".db_escape($project_id);
		if (db_query($sql)) {
			Logging::logEvent($sql,"redcap_projects","MANAGE",$project_id,"project_id = $project_id","Modify project settings");
                }
        }


        /**
         * redcap_module_save_configuration
         * - If selecting project as a source, insert a custom_index_page_note
         * for the source project.
         * - If removing project as a source, remove the custom_index_page_note
         * for the source project.
         * @param int $project_id
         */
        function redcap_module_save_configuration($project_id) {
                
                if ($this->sourceType == '1') {
                        $sourceProjectNote = db_result(db_query("select custom_index_page_note from redcap_projects where project_id = ".db_escape($this->dataSourceName)), 0);

                        if (strpos($sourceProjectNote, 'PID '.$this->dataSourceName)===false) {
                                $sourceProjectNote .= '<div class="yellow">This project was set as the DDP source for project "'.REDCap::escapeHtml($this->Proj->project['app_title']).'" (PID '.$this->dataSourceName.')</div>';
                                $sql = "update redcap_projects set custom_index_page_note = '".db_escape($sourceProjectNote)."' where project_id = ".db_escape($this->dataSourceName);
                                if (db_query($sql)) {
                                        Logging::logEvent($sql,"redcap_projects","MANAGE",$this->dataSourceName,"project_id = ".$this->dataSourceName,"Modify project settings");
                                }
                        }
                }
        }

        /**
         * redcap_every_page_before_render
         * On project pages, replace the global web service urls with the urls
         * specified in the project module config
         * @param int $project_id
         */
        public function redcap_every_page_before_render($project_id) {
                // Note - alternative mechanism would be to set up global noauth pages and pass through the web serive calls
                global $realtime_webservice_enabled, $realtime_webservice_url_data, $realtime_webservice_url_metadata, $realtime_webservice_url_user_access;

                if ($project_id > 0 && $realtime_webservice_enabled) {
                        $realtime_webservice_url_data = $this->dataUrl;
                        $realtime_webservice_url_metadata = $this->metadataUrl;
                        $realtime_webservice_url_user_access = $this->userAccessUrl;
                        //$this->log("Before render, page='{$this->page}'; realtime_webservice_url_metadata='$realtime_webservice_url_metadata'; realtime_webservice_url_data='$realtime_webservice_url_data'; realtime_webservice_url_user_access='$realtime_webservice_url_user_access'");
                }
                
        }
        
        /**
         * redcap_control_center
         * Give info on ddp page that the configuration must be done via the EM
         */
        public function redcap_control_center() {
                if ($this->page === 'ControlCenter/ddp_settings.php') {
                        ?>
<script type="text/javascript">
    $(document).ready(function() {
        var configVia = '<?php echo self::CONFIG_VIA;?>';
        configVia = configVia.replace('%APP_PATH_IMAGES%', app_path_images);
        $('select[name=realtime_webservice_global_enabled]').prop("disabled", "disabled").css('background-color', '#ddd').after(configVia);
        
        var settings = ['realtime_webservice_source_system_custom_name','realtime_webservice_url_metadata','realtime_webservice_url_data','realtime_webservice_url_user_access'];
        settings.forEach(function(elem) {
            $('input[name='+elem+']').prop("disabled", "disabled").css('background-color', '#ddd');
            $('input[name='+elem+']').parent('td').find('button').hide();
        });
    });
</script>
                        <?php
                }
        }

        /**
         * redcap_control_center
         * Give info on ddp page that the configuration must be done via the EM
         */
        public function redcap_every_page_top($project_id) {
                if ($this->page === 'ProjectSetup/index.php') {
                        ?>
<script type="text/javascript">
    $(document).ready(function() {
        var configVia = '<?php echo self::CONFIG_VIA;?>';
        configVia = '<br>'+configVia.replace('%APP_PATH_IMAGES%', app_path_images);
        var btn = $('button[onclick*="realtime_webservice_enabled"]');
        if (btn.length===0) {
            btn = $('button[onclick*="ddpExplainDialog(0);');
        }
        btn.prop("disabled", "disabled")
            .parent('div')
            .append(configVia);
    });
</script>
                        <?php
                }
        }

        public function projectDDP(Array $request) {
                $service = null;
                //$this->log('Create ProjectDDPService: '.$request['service']);
                switch ($request['service']) {
                    case 'data': $service = new ProjectDDPDataService($this, $request); break;
                    case 'metadata': $service = new ProjectDDPMetadataService($this, $request); break;
                    case 'user': $service = new ProjectDDPUserService($this, $request); break;
                    default: return '0'; break;
                }
                try {
                        return $service->getResult();
                } catch (Exception $ex) {
                        $this->log('Exception in '.get_class($service).': '.$ex->getMessage());
                        throw $ex;
                }
        }

        public function log($logtext) {
                if ($this->logger instanceof ProjectDDPLogger) {
                        $this->logger->log($logtext);
                }
        }
}