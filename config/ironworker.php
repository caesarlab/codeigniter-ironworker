<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ironworker
 * 
 * Config for Ironworker
 *
 * @author Sherief Mursjidi <sherief@caesarlab.com>
 */

$config['ironworker'] = array(
	'project_id' => '',
	'token' => '',
	'_api_url' => 'https://worker-aws-us-east-1.iron.io',
	'_api_version' => '2',
	'_project_url' => '/projects',
	'_code_url' => '/codes',
	'_task_url' => '/tasks',
	'_schedule_url' => '/schedules'
);

?>