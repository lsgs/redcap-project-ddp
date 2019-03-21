<?php
/**
 * REDCap External Module: Project DDP
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
error_reporting(0);

if (empty($_POST)) {
        // process post sent as application/json not application/x-www-form-urlencoded
        $input = file_get_contents("php://input");
        $_POST = json_decode($input, true);
        $_REQUEST = array_merge($_REQUEST, $_POST);
}

// global ddp 
// Redirect to project_ddp on project-specific url e.g. for cron-triggered requests

$module = new MCRI\ProjectDDP\ProjectDDP();
$module->log('global_ddp.php request: '.json_encode($_REQUEST));

if (!$module->validateSecret($_GET['secret'], $_POST['user'])) {
        $module->log('project_ddp.php unauthorized: validation of secret failed');
        RestUtility::sendResponse(403, 'Unauthorised DDP request', 'json');
}

// Set params to send in POST request (all JSON-encoded in single parameter 'body')
$params = array(
        'user'=>$_REQUEST['user'], // empty for cron-triggered
        'project_id'=>$_REQUEST['project_id'], 
        'redcap_url'=>$_REQUEST['redcap_url'],
        'id'=>$_REQUEST['id'], 
        'fields'=>$_REQUEST['fields']
);

$project_ddp_url = $module->getUrl('project_ddp.php', true, true).'&pid='.$_REQUEST['project_id'].'&secret='.urlencode($module->getGlobalSecret()).'&service=data';

// Call the URL as POST request
echo http_post($project_ddp_url, $params, 30, 'application/json');