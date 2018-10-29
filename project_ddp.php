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

$module->log('project_ddp.php request: '.json_encode($_REQUEST));

if (!$module->validateSecret($_GET['secret'], $_POST['user'])) {
        $module->log('project_ddp.php unauthorized: validation of secret failed');
        RestUtility::sendResponse(403, 'Unauthorised DDP request', 'json');
}

try {
        $result = $module->projectDDP($_REQUEST);
        $module->log('project_ddp.php result: '.json_encode($result));
} catch (Exception $ex) {
        $module->log('project_ddp.php exception: '.json_encode($ex->getMessage()));
        RestUtility::sendResponse(400, $ex->getMessage(), 'json');
}
RestUtility::sendResponse(200, json_encode($result), 'json');