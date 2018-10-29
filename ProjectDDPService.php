<?php
/**
 * Project DDP Service
 * 
 * Enables project-specific DDP config, and facilitates DDP into a project using 
 * another REDCap project as the DDP source
 * 
 * Testing
 * curl -d "user=luke1&project_id=167&redcap_url=" "https://localhost/redcap/api/?type=module&prefix=project_ddp&page=project_ddp&pid=167&service=metadata"
 */

namespace MCRI\ProjectDDP;

use JsonSerializable;
use Project;
use REDCap;

/**
 * Abstract ProjectDDPService
 *
 * @author luke.stevens
 */
abstract class AbstractProjectDDPService  implements JsonSerializable
{  
        protected $projectDDP;
        protected $request;
        protected $sourceProjectId;
        protected $sourceProject;
        protected $lookupIdField;
        
        public function jsonSerialize() { return get_object_vars($this); }

        public function __construct(ProjectDDP $projectDDP, Array $request) {
                if ($projectDDP->getProj()->project_id != $request['pid']) { 
                        throw new Exception('Request project id does not match DDP project id: '.$request['pid'].' != '.$projectDDP->getProj()->project_id); 
                }
                $this->projectDDP = $projectDDP;
                $this->request = $request;
                $this->sourceProjectId = $this->projectDDP->getDataSourceName();
                $this->sourceProject = new Project($this->sourceProjectId);

                $this->lookupIdField = ($this->projectDDP->getUseSource2ndId() && $this->sourceProject->project['secondary_pk']!=='')
                        ? $this->sourceProject->project['secondary_pk']
                        : $this->sourceProject->table_pk;
        }
        
        abstract public function getResult();
        
        /**
         * getTemporalForms()
         * @return Array Forms that are repeating, in a repeating event or are 
         * designated to more than one event AND contain at least one date or
         * datetime field, the first of which is used as ddp timestamp for 
         * lookup.
         * Dependency on getValTypes() from init_functions.php
         */
        public function getTemporalForms() {
                $formCounts = array_map(function() { return 0; }, $this->projectDDP->getProj()->forms);
                $temporalForms = array();
                foreach ($this->sourceProject->eventsForms as $event => $eventForms) {
                        foreach ($eventForms as $form) {
                                $formCounts[$form]++;
                                if ($this->sourceProject->isRepeatingFormOrEvent($event, $form)) {
                                        if (!in_array($form, $temporalForms)) { $temporalForms[] = $form; }
                                } else {
                                        if ($formCounts[$form]>=2 && !in_array($form, $temporalForms)) { $temporalForms[] = $form; }
                                }
                        }
                }
                
                // temporal forms found, now find the first date/datetime field
                $temporalFormsDDP = array();
                if (count($temporalForms) > 0) {
                        $dateTimeValTypes = array();
                        $dateTimeTypes = array('date','datetime','datetime_seconds');
                        foreach (getValTypes() as $name => $attr) {
                                if (in_array($attr['data_type'], $dateTimeTypes)) { $dateTimeValTypes[] = $name; }
                        }
                        
                        foreach ($temporalForms as $tf) {
                                foreach (array_keys($this->sourceProject->forms[$tf]['fields']) as $f) {
                                        if (in_array($this->sourceProject->metadata[$f]['element_validation_type'], $dateTimeValTypes)) {
                                            $temporalFormsDDP[$tf] = $f;
                                            break;
                                        }
                                }
                        }
                }
                return $temporalFormsDDP;
        }

        public function log($logtext) {
                if ($this->projectDDP->logger instanceof ProjectDDPLogger) {
                        $this->projectDDP->logger->log($logtext);
                }
        }
}

/**
 * User Service verifies that user has appropriate access to both source and 
 * destination projects (set level in project module config).
 */
class ProjectDDPUserService extends AbstractProjectDDPService 
{
        public function getResult() {
                if ($this->projectDDP->getSuperUser()) { return 1; }
                
                $result = false;
                $user = db_escape($this->request['user']);
                $sourceProjectId = db_escape($this->sourceProjectId);
                $destProjectId = db_escape($this->projectDDP->getProj()->project_id);
                
                $destProjectPermissions = db_fetch_array(db_query("select redcap_user_rights.project_id, username, redcap_user_rights.role_id, coalesce(redcap_user_roles.realtime_webservice_adjudicate, redcap_user_rights.realtime_webservice_adjudicate, '0') as realtime_webservice_adjudicate from redcap_user_rights left outer join redcap_user_roles on redcap_user_rights.role_id = redcap_user_roles.role_id where username='$user' and redcap_user_rights.project_id=$destProjectId and (expiration is null or expiration > '".NOW."')"));
                $sourceProjectPermissions = db_fetch_array(db_query("select redcap_user_rights.project_id, username, redcap_user_rights.role_id, coalesce(redcap_user_roles.data_export_tool, redcap_user_rights.data_export_tool, '0') as data_export_tool from redcap_user_rights left outer join redcap_user_roles on redcap_user_rights.role_id = redcap_user_roles.role_id where username='$user' and redcap_user_rights.project_id=$sourceProjectId and (expiration is null or expiration > '".NOW."')"));
                
                switch ($this->projectDDP->getProjectSetting('redcap-project-source-permissions')) {
                    case '0':
                        // need current adjudication access in destination project only
                        $result = $destProjectPermissions['realtime_webservice_adjudicate']=='1';
                        break;
                    case '1':
                        // need current adjudication access in destination project and any current access to source project
                        $result = $destProjectPermissions['realtime_webservice_adjudicate']=='1' && count($sourceProjectPermissions)>0;
                        break;
                    case '2':
                        // need current adjudication access in destination project and current full data set export permission in source project and current adjudication access in destination
                        $result = $destProjectPermissions['realtime_webservice_adjudicate']=='1' && $sourceProjectPermissions['data_export_tool']=='1';
                        break;
                    default:
                        break;
                }
                
                return (int)$result;
        }
}

/**
 * Metadata Service returns metadata of source project.
 */
class ProjectDDPMetadataService extends AbstractProjectDDPService 
{
        public function getResult() {
                $temporalForms = $this->getTemporalForms();
                $dd = REDCap::getDataDictionary($this->sourceProjectId, 'array');
                $result = array();
                
                foreach ($dd as $fieldName => $attr) {
                        $result[] = array(
                            "field"=>$fieldName,
                            "label"=>$attr['field_label'],
                            "description"=>trim("{$attr['field_type']} {$attr['text_validation_type_or_show_slider_number']} {$attr['select_choices_or_calculations']} {$attr['field_note']}"),
                            "temporal"=>(array_key_exists($attr['form_name'], $temporalForms)) ? 1 : 0,
                            "category"=>$attr['form_name'],
                            "identifier"=>($fieldName===$this->lookupIdField) ? '1' : '0' // "identifier" as in "record id candidate", not as in "field contains PHI"
                        );
                }
                return $result;
        }
}

/**
 * Data Service returns data of source project.
 * TODO Check DAG of user in source project?
 */
class ProjectDDPDataService extends AbstractProjectDDPService
{
        public function getResult() {
                $result = array();
                
                // check user auth separately yo user auth service which does not operate for preview and only once per redcap auth session
                if (!$this->checkUserAuth()) { return $result; }
                
                if (!is_numeric($this->request['id']) || !is_array($this->request['fields'])) { return $result; }
                
                $searchFields = array();
                for ($i=0; $i<count($this->request['fields']); $i++) {
                        $fld = $this->request['fields'][$i]['field'];
                        if (array_key_exists($fld, $this->sourceProject->metadata)) { 
                                $searchFields[] = $fld; 
                        } else {
                                unset($this->request['fields'][$i]);
                        }
                }

                if (count($searchFields) === 0) { return $result; }

                $temporalForms = $this->getTemporalForms();
                $stampFields = array_values($temporalForms);
                $readFields = array_merge($searchFields, $stampFields);

                $groupFilter = $this->getGroupFilter();
                if ($groupFilter===false) { return $result; } // user in a dag, and expected matching dag name not found in source 
                
                $recordData = REDCap::getData(array(
                        'project_id' => $this->sourceProjectId,
                        'fields' => $readFields,
                        'groups' => $groupFilter,
                        'filterLogic' => "[{$this->lookupIdField}]='{$this->request['id']}'",
                        'filterType' => 'RECORD'
                ));

                if (count($recordData) === 0) { return $result; }

                reset($this->request['fields']);
                foreach ($this->request['fields'] as $fld) {
                    
                        if (array_key_exists('timestamp_min', $fld)) {
                                // field is temporal - get value between min and max timestamp
                                $formFieldIsOn = $this->sourceProject->metadata[$fld['field']]['form_name'];
                                $stampField = $temporalForms[$formFieldIsOn];
                                $fldResult = $this->getRangeValue($recordData, $fld['field'], $stampField, $fld['timestamp_min'], $fld['timestamp_max']);
                        } else {
                                // field is not temporal - just get single value (the first if repeated and no date field on form)
                                $fldResult = $this->getSingleValue($recordData, $fld['field']);
                        }
                        $result = array_merge($result, $fldResult);
                }
                
                return $result;
        }

        /**
         * getGroupFilter()
         * @return null - when user not in dag, or project not set to use dag filtering
         * @return int  - dag id in source project to restrict results returned
         * @return false - expected dag name not found in source project: return no results 
         */
        protected function getGroupFilter() {
                if (!$this->projectDDP->getProjectSetting('redcap-project-dag-filter')) { return null; } // no dag filtering required
                $destpid = db_escape($this->projectDDP->getProj()->project_id);
                $sourcepid = db_escape($this->projectDDP->getDataSourceName());
                $user = db_escape($this->request['user']);
                $userdagname = db_result(db_query("select group_name from redcap_user_rights inner join redcap_data_access_groups on redcap_user_rights.group_id = redcap_data_access_groups.group_id where redcap_user_rights.project_id=$destpid and username='$user' "));
                if (!$userdagname) { return null; } // no dag filtering required
                $sourcedagid = db_result(db_query("select group_id from redcap_data_access_groups where project_id=$sourcepid and group_name='".db_escape($userdagname)."'"));
                if (!$sourcedagid)  { return false; } // expected dag name not found in source project -> no results will be returned
                return (int)$sourcedagid;
        }
        
        protected function getSingleValue($data, $searchField) {
                $result = array();
                foreach ($data as $events) {
                        foreach ($events as $event_data) {
                                if (array_key_exists($searchField, $event_data)) {
                                        if ($event_data[$searchField]!=='') { // first non-blank value only
                                                if (is_array($event_data[$searchField])) { // checkbox
                                                        foreach ($event_data[$searchField] as $opt => $checked) {
                                                                if ($checked==='1') {
                                                                        $result[] = array(
                                                                                'field' => $searchField,
                                                                                'value' => $opt
                                                                        );
                                                                }
                                                        }
                                                } else {
                                                        $result[] = array(
                                                                'field' => $searchField,
                                                                'value' => $event_data[$searchField]
                                                        );
                                                }
                                                break; 
                                        } 
                                }
                        }
                }

                // Check repeating events/forms with no date/datetime fields if necessary
                if (count($result)===0 && array_key_exists('repeat_instances', $data)) {
                        foreach ($data['repeat_instances'] as $rpt_data_event) {
                                foreach ($rpt_data_event as $repeating_thing) {
                                        foreach ($repeating_thing as $instance) {
                                                if (array_key_exists($searchField, $instance)) {
                                                        if ($instance[$searchField]!=='') {  // first non-blank value only
                                                                $result[] = array(
                                                                        'field' => $searchField,
                                                                        'value' => $instance[$searchField]
                                                                );
                                                                break; 
                                                        }
                                                }
                                        }
                                }
                        }
                }

                return $result;
        }
        
        protected function getRangeValue($data, $searchField, $stampField, $minStamp, $maxStamp) {

                $minStamp = self::addTimeComponentToStamp($minStamp, '00:00:00');
                $maxStamp = self::addTimeComponentToStamp($maxStamp, '23:59:59');

                $result = array();
                foreach ($data as $rec => $events) {
                        foreach ($events as $event_data) {
                                if (array_key_exists($stampField, $event_data) &&
                                    array_key_exists($searchField, $event_data) &&
                                    !empty($event_data[$stampField]) && 
                                    !empty($event_data[$searchField]) ) {
                                    
                                        $eventStampVal = self::addTimeComponentToStamp($event_data[$stampField], '00:00:00');
                                        
                                        if ($eventStampVal >= $minStamp &&
                                            $eventStampVal <= $maxStamp) {
                                                $result[] = array(
                                                        'field' => $searchField,
                                                        'value' => $event_data[$searchField],
                                                        'timestamp' => $event_data[$stampField]
                                                );
                                        }
                                        
                                }
                        }
                }

                // Check repeating events/forms if necessary
                if (count($result)===0 && array_key_exists('repeat_instances', $data[$rec])) {
                        foreach ($data[$rec]['repeat_instances'] as $rpt_data_event) {
                                foreach ($rpt_data_event as $repeating_thing) {
                                        foreach ($repeating_thing as $instance) {
                                                if (array_key_exists($stampField, $instance) &&
                                                    array_key_exists($searchField, $instance) &&
                                                    !empty($instance[$stampField]) && 
                                                    !empty($instance[$searchField]) ) {

                                                        $instanceStampVal = self::addTimeComponentToStamp($instance[$stampField], '00:00:00');

                                                        if ($instanceStampVal >= $minStamp &&
                                                            $instanceStampVal <= $maxStamp) {
                                                                $result[] = array(
                                                                        'field' => $searchField,
                                                                        'value' => $instance[$searchField],
                                                                        'timestamp' => $instance[$stampField]
                                                                );
                                                        }

                                                }
                                        }
                                }
                        }
                }

                return $result;
        }
        
        protected static function addTimeComponentToStamp($stamp, $timePart) {
                $FULL_LENGTH = strlen('2001-01-01 01:01:01');
                $timePart = ' '.$timePart;
                $stamp = trim((string)$stamp);
                
                if (strlen($stamp)>=$FULL_LENGTH) { return substr($stamp, 0, $FULL_LENGTH); }
                
                return $stamp.substr($timePart, -1*($FULL_LENGTH-strlen($stamp)));
        }

        protected function checkUserAuth() {
                $userService = new ProjectDDPUserService( $this->projectDDP, $this->request );
                return $userService->getResult();
        }
}
